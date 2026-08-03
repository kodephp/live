<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Agora;

use Kode\Live\Contracts\LiveEvent;
use Kode\Live\Contracts\SignedUrlProvider;
use Kode\Live\LiveStreaming\AbstractStreamKeyPlatform;
use Kode\Live\Support\Clock\SystemClock;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Event\StreamEndedEvent;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Event\UnknownEvent;
use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\InvalidWebhookException;
use Kode\Live\Support\Validation\WebhookGuard;
use Kode\Live\Support\Webhook\FieldExtractor;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 声网 Agora（推流到 CDN）平台驱动。
 *
 * 覆盖 Agora「推流到 CDN（Publish to CDN）」场景：RTMP 推流地址 `rtmp://{domain}/{app}/{stream}`，其中
 * stream 即 RTC 频道名（或转推任务指定的流名）。拉流默认不提供（Agora 原生走 RTC），若已配置 CDN
 * 域名则支持 HLS / FLV。回调验签复用通用 `md5(排序参数 + secret)` 方案
 * （{@see FieldExtractor::verifySortedMd5}）。
 *
 * 注：Agora 频道的 RTC Token 鉴权由官方 SDK 负责，本驱动仅做「推流到 CDN 的 RTMP 地址拼装 +
 * 回调归一化（md5 方案）」。
 */
final class AgoraPlatform extends AbstractStreamKeyPlatform
{
    public function __construct(
        private readonly AgoraConfig $config,
        RecordingConfig $recordingConfig,
        ?SignedUrlProvider $signedUrlProvider = null,
        ClockInterface $clock = new SystemClock(),
        LoggerInterface $logger = new NullLogger(),
        int $defaultTtlSeconds = 3600,
        int $webhookMaxAgeSeconds = 300,
    ) {
        parent::__construct($recordingConfig, $signedUrlProvider, $clock, $logger, $defaultTtlSeconds, $webhookMaxAgeSeconds);
    }

    public function name(): Platform
    {
        return Platform::Agora;
    }

    protected function defaultPushDomain(): string
    {
        return $this->config->pushDomain;
    }

    protected function defaultPullDomain(): string
    {
        return $this->config->pullDomain;
    }

    protected function defaultAppName(): string
    {
        return $this->config->appName;
    }

    protected function webhookSecret(): string
    {
        return $this->config->callbackSecret;
    }

    public function parseWebhook(string $payload, array $headers = []): LiveEvent
    {
        if ($this->config->callbackSecret === '') {
            throw ConfigurationException::missing('agora.callbackSecret');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!\is_array($decoded)) {
            throw InvalidWebhookException::malformed('非合法 JSON');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;
        FieldExtractor::verifySortedMd5($data, $this->config->callbackSecret);
        WebhookGuard::assertFresh($data, $this->clock->now()->getTimestamp(), $this->webhookMaxAgeSeconds);

        $event = FieldExtractor::stringField($data, 'event', FieldExtractor::stringField($data, 'action'));
        $stream = FieldExtractor::stringField($data, 'channel', FieldExtractor::stringField($data, 'stream'));
        $occurredAt = $this->clock->now();

        return match ($event) {
            'live_start', 'start', 'join', 'channel_join' => new StreamStartedEvent($this->name(), $stream, $occurredAt, $data),
            'live_end', 'end', 'leave', 'channel_leave' => new StreamEndedEvent($this->name(), $stream, $occurredAt, $data),
            default => new UnknownEvent($this->name(), $stream, $occurredAt, $data),
        };
    }
}
