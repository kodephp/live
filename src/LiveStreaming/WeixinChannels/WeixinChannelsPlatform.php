<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\WeixinChannels;

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
 * 微信视频号直播平台驱动。
 *
 * 推流 RTMP（域名 + 流名），拉流可选（自有 CDN 时配置 pullDomain）；回调验签采用
 * md5(排序参数 + token) 的通用方案。
 *
 * 说明：视频号权威的回调（微信消息加解密 / msg_signature + AES）需在微信开放平台侧处理，
 * 本驱动仅提供「配置驱动的可复现地址拼装 + 回调归一化」层，便于在服务端统一编排与测试；
 * 接入时请用官方 SDK 完成消息解密，再将其解析结果适配到本驱动的事件映射。
 */
final class WeixinChannelsPlatform extends AbstractStreamKeyPlatform
{
    public function __construct(
        private readonly WeixinChannelsConfig $config,
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
        return Platform::WeixinChannels;
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
        return $this->config->callbackToken;
    }

    public function parseWebhook(string $payload, array $headers = []): LiveEvent
    {
        if ($this->config->callbackToken === '') {
            throw ConfigurationException::missing('weixin_channels.callbackToken');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!\is_array($decoded)) {
            throw InvalidWebhookException::malformed('非合法 JSON');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;
        FieldExtractor::verifySortedMd5($data, $this->config->callbackToken);
        WebhookGuard::assertFresh($data, $this->clock->now()->getTimestamp(), $this->webhookMaxAgeSeconds);

        $event = FieldExtractor::stringField($data, 'event', FieldExtractor::stringField($data, 'action'));
        $stream = FieldExtractor::stringField(
            $data,
            'stream',
            FieldExtractor::stringField($data, 'room_id', FieldExtractor::stringField($data, 'live_id')),
        );
        $occurredAt = $this->clock->now();

        return match ($event) {
            'live_started', 'live_start', 'start', 'push_start' => new StreamStartedEvent($this->name(), $stream, $occurredAt, $data),
            'live_finished', 'live_end', 'end', 'push_stop' => new StreamEndedEvent($this->name(), $stream, $occurredAt, $data),
            default => new UnknownEvent($this->name(), $stream, $occurredAt, $data),
        };
    }
}
