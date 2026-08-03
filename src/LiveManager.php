<?php

declare(strict_types=1);

namespace Kode\Live;

use Kode\Live\Contracts\LivePlatform;
use Kode\Live\Contracts\SignedUrlProvider;
use Kode\Live\Download\ResumableDownloader;
use Kode\Live\Pipeline\LivePipeline;
use Kode\Live\Support\Dto\PlaybackUrl;
use Kode\Live\Support\Dto\Recording;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Dto\StreamUrlSet;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\UnsupportedFeatureException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 直播平台驱动管理器（对外主入口 / 门面）。
 *
 * 支持直接注册已构建的驱动实例，或通过工厂闭包惰性构建；按平台标识取用。
 *
 * 便捷编排：可在管理器层面注入共享的 SignedUrlProvider / PSR-14 事件调度器 / Logger，
 * 通过 pipeline() 一步得到接线完毕的 LivePipeline，或通过 playback() 在驱动未自带
 * SignedUrlProvider 时也能生成回放签名地址——降低多平台接入的样板代码。
 */
final class LiveManager
{
    /** @var array<string, LivePlatform> */
    private array $resolved = [];

    /** @var array<string, callable(): LivePlatform> */
    private array $factories = [];

    private ?SignedUrlProvider $provider = null;
    private ?EventDispatcherInterface $dispatcher = null;
    private ?LoggerInterface $logger = null;

    /**
     * @param SignedUrlProvider|null $provider 共享的对象存储签名提供者（playback() 兜底用）
     * @param EventDispatcherInterface|null $dispatcher 共享的 PSR-14 事件调度器（pipeline() 接线用）
     * @param LoggerInterface|null $logger 共享日志器（pipeline() 接线用）
     */
    public function __construct(
        ?SignedUrlProvider $provider = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->provider = $provider;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    /**
     * 注入共享 SignedUrlProvider（返回自身，便于链式调用）。
     */
    public function withProvider(SignedUrlProvider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * 注入共享 PSR-14 事件调度器（返回自身，便于链式调用）。
     */
    public function withDispatcher(EventDispatcherInterface $dispatcher): self
    {
        $this->dispatcher = $dispatcher;

        return $this;
    }

    /**
     * 注入共享 Logger（返回自身，便于链式调用）。
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * 为指定平台构建一条接线完毕的 LivePipeline（自动注入共享 dispatcher / logger）。
     *
     * 把「取驱动 → 建流水线」收敛为一行调用，编排回放 / 归档时无需手动拼装。
     */
    public function pipeline(Platform|string $platform, ?ResumableDownloader $downloader = null): LivePipeline
    {
        return new LivePipeline(
            $this->driver($platform),
            downloader: $downloader,
            dispatcher: $this->dispatcher,
            logger: $this->logger ?? new NullLogger(),
        );
    }

    /**
     * 生成回放签名地址；若驱动自身未注入 SignedUrlProvider，则回退到管理器的共享 provider。
     *
     * 失败（既无驱动 provider 也无共享 provider，且录制无 sourceUrl）时返回 null，不抛异常。
     */
    public function playback(Platform|string $platform, Recording $recording, int $ttl = 3600): ?PlaybackUrl
    {
        $driver = $this->driver($platform);

        try {
            return $driver->playbackUrl($recording, $ttl);
        } catch (UnsupportedFeatureException) {
            if ($this->provider !== null) {
                return new PlaybackUrl($this->provider->presign($recording->bucket, $recording->objectKey, $ttl));
            }

            return null;
        }
    }

    /**
     * 注册一个已构建好的驱动实例。
     */
    public function register(LivePlatform $platform): self
    {
        $this->resolved[$platform->name()->value] = $platform;

        return $this;
    }

    /**
     * 注册一个惰性驱动工厂（首次取用时才构建）。
     *
     * @param callable(): LivePlatform $factory
     */
    public function extend(Platform|string $platform, callable $factory): self
    {
        $this->factories[$this->key($platform)] = $factory;

        return $this;
    }

    public function has(Platform|string $platform): bool
    {
        $key = $this->key($platform);

        return isset($this->resolved[$key]) || isset($this->factories[$key]);
    }

    /**
     * 取得指定平台的驱动。
     */
    public function driver(Platform|string $platform): LivePlatform
    {
        $key = $this->key($platform);

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if (isset($this->factories[$key])) {
            $instance = ($this->factories[$key])();
            $this->resolved[$key] = $instance;

            return $instance;
        }

        throw ConfigurationException::invalid('platform', \sprintf('未注册的平台驱动：%s', $key));
    }

    /**
     * @return list<string>
     */
    public function registered(): array
    {
        return array_values(array_unique([
            ...array_keys($this->resolved),
            ...array_keys($this->factories),
        ]));
    }

    private function key(Platform|string $platform): string
    {
        return $platform instanceof Platform ? $platform->value : $platform;
    }

    /**
     * 一次性取得一路直播的推流地址、所需协议的拉流地址，以及（可选）回放地址。
     *
     * 省去分别调用 pushUrl / pullUrl / playbackUrl 的样板代码。
     *
     * @param list<StreamProtocol> $pullProtocols 需要生成的拉流协议，默认 FLV + HLS
     */
    public function urlBundle(
        Platform|string $platform,
        StreamRequest $request,
        array $pullProtocols = [StreamProtocol::Flv, StreamProtocol::Hls],
        ?Recording $recording = null,
    ): StreamUrlSet {
        $driver = $this->driver($platform);

        $push = $driver->pushUrl($request);

        $pull = [];
        foreach ($pullProtocols as $protocol) {
            if ($driver->supports($protocol)) {
                $pull[] = $driver->pullUrl($request, $protocol);
            }
        }

        $playback = null;
        if ($recording !== null) {
            try {
                $playback = $driver->playbackUrl($recording);
            } catch (\Throwable) {
                // 无 SignedUrlProvider 且录制无 sourceUrl 等场景：回放地址留空，不阻断主流程。
                $playback = null;
            }
        }

        return new StreamUrlSet($push, $pull, $playback);
    }
}
