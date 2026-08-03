<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Qiniu;

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
 * 七牛直播云（Pili）平台驱动。
 *
 * 推流 RTMP（`/{hub}/{streamTitle}`，hub 对应 AppName），拉流 HLS / FLV；回调验签采用
 * 通用 `md5(排序参数 + secret)` 方案，复用 {@see FieldExtractor::verifySortedMd5}。
 *
 * 注：七牛真实的流 hub 回调签名（基于 HMAC-SHA1 的 Authorization）与播放鉴权串由七牛开放平台
 * 下发，本驱动只做「配置驱动地址拼装 + 回调归一化（md5 方案）」；若你的回调网关使用七牛原生
 * 签名，请在本类 {@see parseWebhook()} 中替换为对应校验，或前置一个签名转换网关。
 */
final class QiniuPlatform extends AbstractStreamKeyPlatform
{
    public function __construct(
        private readonly QiniuConfig $config,
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
        return Platform::Qiniu;
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
        return $this->config->hub;
    }

    protected function webhookSecret(): string
    {
        return $this->config->callbackSecret;
    }

    public function parseWebhook(string $payload, array $headers = []): LiveEvent
    {
        if ($this->config->callbackSecret === '') {
            throw ConfigurationException::missing('qiniu.callbackSecret');
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
        $stream = FieldExtractor::stringField($data, 'stream', FieldExtractor::stringField($data, 'streamTitle'));
        $occurredAt = $this->clock->now();

        return match ($event) {
            'live_start', 'start', 'publish', 'stream_publish' => new StreamStartedEvent($this->name(), $stream, $occurredAt, $data),
            'live_end', 'end', 'disconnect', 'stream_disconnect' => new StreamEndedEvent($this->name(), $stream, $occurredAt, $data),
            default => new UnknownEvent($this->name(), $stream, $occurredAt, $data),
        };
    }
}
