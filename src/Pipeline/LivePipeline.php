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
 *   1) handleWebhook() 接收平台回调，验签并归一化为事件（同时可派发到 PSR-14 事件总线）。
 *   2) 若为 RecordingReadyEvent，用 playbackFor() 生成回放地址，或 archive() 落地到本地。
 */
final class LivePipeline
{
    public function __construct(
        private readonly LivePlatform $platform,
        private readonly ?Downloader $downloader = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * 接收并处理平台回调，返回归一化事件；若配置了事件总线则同时派发。
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
}
