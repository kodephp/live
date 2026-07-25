<?php

declare(strict_types=1);

namespace Kode\Live\Pipeline;

use Kode\Live\Contracts\Downloader;
use Kode\Live\Contracts\LiveEvent;
use Kode\Live\Contracts\LivePlatform;
use Kode\Live\Support\Dto\DownloadOptions;
use Kode\Live\Support\Dto\DownloadResult;
use Kode\Live\Support\Dto\PlaybackUrl;
use Kode\Live\Support\Event\RecordingReadyEvent;
use Kode\Live\Support\Exception\UnsupportedFeatureException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 直播编排流水线：把「回调事件 → 回放地址 → 下载归档」串成一条链，直播与存储在此天然结合。
 *
 * 典型用法：
 *   1) handleWebhook() 接收平台回调，验签并归一化为事件，随后：派发到 PSR-14 事件总线（若配置），
 *      并调用通过 on() 注册的本地监听器（按优先级顺序、互不影响地执行）。
 *   2) 若为 RecordingReadyEvent，用 playbackFor() 生成回放地址，或 archive() 落地到本地。
 */
final class LivePipeline
{
    /** @var list<array{class: string, listener: callable, priority: int}> */
    private array $listeners = [];

    public function __construct(
        private readonly LivePlatform $platform,
        private readonly ?Downloader $downloader = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * 注册本地事件监听器。
     *
     * 归一化事件若 `instanceof $eventClass` 即触发；多个监听器按 priority 降序执行，
     * 单个监听器抛错不会中断流水线（错误被记录后跳过），保证编排的健壮性。
     *
     * @param string $eventClass 监听的事件类（如 RecordingReadyEvent::class，支持父类/接口匹配）
     * @param callable $listener 接收该事件对象的回调（实际入参为 $eventClass 实例）
     * @param int $priority 优先级，数值越大越先执行
     */
    public function on(string $eventClass, callable $listener, int $priority = 0): self
    {
        $this->listeners[] = ['class' => $eventClass, 'listener' => $listener, 'priority' => $priority];

        return $this;
    }

    /**
     * 接收并处理平台回调，返回归一化事件；若配置了事件总线或本地监听器则同时派发。
     *
     * @param array<string, string> $headers
     */
    public function handleWebhook(string $payload, array $headers = []): LiveEvent
    {
        $event = $this->platform->parseWebhook($payload, $headers);

        $this->logger->info('收到直播回调事件', [
            'platform' => $event->platform()->value,
            'type' => $event->type()->value,
            'stream' => $event->streamName(),
        ]);

        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch($event);
        }

        $this->dispatchLocal($event);

        return $event;
    }

    /**
     * 为「录制完成」事件生成回放地址。
     */
    public function playbackFor(RecordingReadyEvent $event, int $ttlSeconds = 3600): PlaybackUrl
    {
        return $this->platform->playbackUrl($event->recording, $ttlSeconds);
    }

    /**
     * 把录制文件下载归档到本地。
     *
     * 优先使用回调携带的直链；没有则回退到签名回放地址。
     */
    public function archive(
        RecordingReadyEvent $event,
        string $destination,
        ?DownloadOptions $options = null,
        int $ttlSeconds = 3600,
    ): DownloadResult {
        if ($this->downloader === null) {
            throw UnsupportedFeatureException::feature($this->platform->name(), '下载归档（未注入 Downloader）');
        }

        $source = $event->recording->sourceUrl
            ?? $this->platform->playbackUrl($event->recording, $ttlSeconds)->url;

        return $this->downloader->download($source, $destination, $options);
    }

    /**
     * 派发到本地监听器：按优先级降序执行，单个失败不影响其余监听器。
     */
    private function dispatchLocal(LiveEvent $event): void
    {
        $matched = [];
        foreach ($this->listeners as $entry) {
            if ($event instanceof $entry['class']) {
                $matched[] = $entry;
            }
        }

        usort($matched, static function (mixed $a, mixed $b): int {
            /** @var array{class: string, listener: callable, priority: int} $a */
            /** @var array{class: string, listener: callable, priority: int} $b */
            return $b['priority'] <=> $a['priority'];
        });

        foreach ($matched as $entry) {
            try {
                ($entry['listener'])($event);
            } catch (\Throwable $e) {
                $this->logger->error('直播事件监听器执行失败', [
                    'event' => $event->type()->value,
                    'listener' => $entry['class'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
