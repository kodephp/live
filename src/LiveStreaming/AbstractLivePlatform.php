<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming;

use DateTimeImmutable;
use Kode\Live\Contracts\LivePlatform;
use Kode\Live\Contracts\SignedUrlProvider;
use Kode\Live\Support\Clock\SystemClock;
use Kode\Live\Support\Dto\PlaybackUrl;
use Kode\Live\Support\Dto\Recording;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Exception\UnsupportedFeatureException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 平台驱动基类：收敛过期时间计算、协议校验、回放地址生成等公共逻辑。
 */
abstract class AbstractLivePlatform implements LivePlatform
{
    public function __construct(
        protected readonly RecordingConfig $recordingConfig,
        protected readonly ?SignedUrlProvider $signedUrlProvider = null,
        protected readonly ClockInterface $clock = new SystemClock(),
        protected readonly LoggerInterface $logger = new NullLogger(),
        protected readonly int $defaultTtlSeconds = 3600,
        protected readonly int $webhookMaxAgeSeconds = 300,
    ) {
    }

    public function recordingConfig(): RecordingConfig
    {
        return $this->recordingConfig;
    }

    public function playbackUrl(Recording $recording, int $ttlSeconds = 3600): PlaybackUrl
    {
        if ($this->signedUrlProvider === null) {
            if ($recording->sourceUrl !== null && $recording->sourceUrl !== '') {
                return new PlaybackUrl($recording->sourceUrl);
            }

            throw UnsupportedFeatureException::feature(
                $this->name(),
                '回放签名地址（未注入 SignedUrlProvider，且录制无可用 sourceUrl）',
            );
        }

        $url = $this->signedUrlProvider->presign($recording->bucket, $recording->objectKey, $ttlSeconds);

        return new PlaybackUrl($url, $this->clock->now()->modify(\sprintf('+%d seconds', $ttlSeconds)));
    }

    /**
     * 计算过期 Unix 时间戳；未显式指定时按默认有效期。
     */
    protected function resolveExpiresTimestamp(?DateTimeImmutable $expiresAt): int
    {
        $moment = $expiresAt
            ?? $this->clock->now()->modify(\sprintf('+%d seconds', $this->defaultTtlSeconds));

        return $moment->getTimestamp();
    }

    protected function assertSupported(StreamProtocol $protocol): void
    {
        if (!$this->supports($protocol)) {
            throw UnsupportedFeatureException::protocol($this->name(), $protocol);
        }
    }
}
