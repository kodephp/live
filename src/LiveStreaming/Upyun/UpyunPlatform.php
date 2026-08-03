<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Upyun;

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
 * 又拍云直播平台驱动。
 *
 * 推流 RTMP（`/{app}/{stream}`，app 缺省时直接用 `{服务名}.uplive-upaiyun.com/{stream}`），拉流
 * HLS / FLV；回调验签采用通用 `md5(排序参数 + secret)` 方案，复用
 * {@see FieldExtractor::verifySortedMd5}。
 *
 * 注：又拍云真实的推流鉴权（token）与回调签名由又拍云下发，本驱动只做「配置驱动地址拼装 +
 * 回调归一化（md5 方案）」；若你的回调网关使用又拍云原生签名，请在本类 {@see parseWebhook()}
 * 中替换为对应校验，或前置一个签名转换网关。
 */
final class UpyunPlatform extends AbstractStreamKeyPlatform
{
    public function __construct(
        private readonly UpyunConfig $config,
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
        return Platform::Upyun;
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
            throw ConfigurationException::missing('upyun.callbackSecret');
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

        $event = FieldExtractor::stringField($data, 'action', FieldExtractor::stringField($data, 'event'));
        $stream = FieldExtractor::stringField($data, 'stream', FieldExtractor::stringField($data, 'stream_name'));
        $occurredAt = $this->clock->now();

        return match ($event) {
            'live_start', 'start', 'publish', 'stream_start' => new StreamStartedEvent($this->name(), $stream, $occurredAt, $data),
            'live_stop', 'stop', 'unpublish', 'stream_stop' => new StreamEndedEvent($this->name(), $stream, $occurredAt, $data),
            default => new UnknownEvent($this->name(), $stream, $occurredAt, $data),
        };
    }
}
