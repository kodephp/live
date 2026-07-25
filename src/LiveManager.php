<?php

declare(strict_types=1);

namespace Kode\Live;

use Kode\Live\Contracts\LivePlatform;
use Kode\Live\Support\Dto\Recording;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Dto\StreamUrlSet;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Exception\ConfigurationException;

/**
 * 直播平台驱动管理器（对外主入口 / 门面）。
 *
 * 支持直接注册已构建的驱动实例，或通过工厂闭包惰性构建；按平台标识取用。
 */
final class LiveManager
{
    /** @var array<string, LivePlatform> */
    private array $resolved = [];

    /** @var array<string, callable(): LivePlatform> */
    private array $factories = [];

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
