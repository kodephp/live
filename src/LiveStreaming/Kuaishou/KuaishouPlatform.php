<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Kuaishou;

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
 * 快手直播平台驱动。
 *
 * 推流 RTMP（域名 + app + 流名），拉流 FLV / HLS；回调验签采用 md5(排序参数 + secret)，
 * 与 B站 / 抖音同构，统一复用 FieldExtractor。
 *
 * 注：真实的播放鉴权串与推流地址由快手开放平台 API 下发，本驱动只负责地址拼装与回调归一化；
 * 开播 / 关播的开放平台 API 调用请用官方 SDK，不在本包范围。
 */
final class KuaishouPlatform extends AbstractStreamKeyPlatform
{
    public function __construct(
        private readonly KuaishouConfig $config,
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
        return Platform::Kuaishou;
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
            throw ConfigurationException::missing('kuaishou.callbackSecret');
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
        $stream = FieldExtractor::stringField($data, 'stream', FieldExtractor::stringField($data, 'room_id'));
        $occurredAt = $this->clock->now();

        return match ($event) {
            'live_start', 'start', 'on_live_start' => new StreamStartedEvent($this->name(), $stream, $occurredAt, $data),
            'live_end', 'end', 'on_live_end' => new StreamEndedEvent($this->name(), $stream, $occurredAt, $data),
            default => new UnknownEvent($this->name(), $stream, $occurredAt, $data),
        };
    }
}
