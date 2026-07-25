<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Huawei;

use DateTimeImmutable;
use Kode\Live\Contracts\LiveEvent;
use Kode\Live\Contracts\SignedUrlProvider;
use Kode\Live\LiveStreaming\AbstractLivePlatform;
use Kode\Live\Support\Clock\SystemClock;
use Kode\Live\Support\Dto\PullUrl;
use Kode\Live\Support\Dto\PushUrl;
use Kode\Live\Support\Dto\Recording;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\RecordingReadyEvent;
use Kode\Live\Support\Event\StreamEndedEvent;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Event\UnknownEvent;
use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\InvalidWebhookException;
use Kode\Live\Support\Signature\AliyunLiveSigner;
use Kode\Live\Support\Validation\WebhookGuard;
use Kode\Live\Support\Webhook\FieldExtractor;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 华为云直播（CSS）平台驱动。
 *
 * 华为云 Key 防盗链的 auth_key 算法（`md5("/uri-timestamp-rand-uid-key")`）与阿里云直播
 * 鉴权 A 方式完全一致，故直接复用 {@see AliyunLiveSigner} 拼装带鉴权串的推拉流地址，
 * 避免重复实现同一套数学。录制由 CSS 侧按配置自动落 OBS，本驱动负责地址签名与回调归一化。
 *
 * 注：华为云直播回调的字段名与签名串拼接方式随控制台配置可能不同，本驱动采用
 * `sign = md5(callbackKey + t)` 的通用方案并暴露 callbackKey 参数；接入时请以你自己的
 * 华为云直播控制台回调配置为准对齐字段名与密钥。
 */
final class HuaweiLivePlatform extends AbstractLivePlatform
{
    private readonly AliyunLiveSigner $signer;

    public function __construct(
        private readonly HuaweiLiveConfig $config,
        RecordingConfig $recordingConfig,
        ?SignedUrlProvider $signedUrlProvider = null,
        ?AliyunLiveSigner $signer = null,
        ClockInterface $clock = new SystemClock(),
        LoggerInterface $logger = new NullLogger(),
        int $defaultTtlSeconds = 3600,
        int $webhookMaxAgeSeconds = 300,
    ) {
        parent::__construct($recordingConfig, $signedUrlProvider, $clock, $logger, $defaultTtlSeconds, $webhookMaxAgeSeconds);
        $this->signer = $signer ?? new AliyunLiveSigner();
    }

    public function name(): Platform
    {
        return Platform::Huawei;
    }

    public function supports(StreamProtocol $protocol): bool
    {
        return \in_array($protocol, [
            StreamProtocol::Rtmp,
            StreamProtocol::Flv,
            StreamProtocol::Hls,
        ], true);
    }

    public function pushUrl(StreamRequest $request): PushUrl
    {
        $app = $request->appName ?? $this->config->defaultAppName;
        $expires = $this->resolveExpiresTimestamp($request->expiresAt);

        $url = $this->signer->buildUrl(
            StreamProtocol::Rtmp->scheme(),
            $this->config->pushDomain,
            $app,
            $request->streamName,
            '',
            $this->config->pushKey,
            $expires,
            $request->params,
        );

        return new PushUrl($url, StreamProtocol::Rtmp, (new DateTimeImmutable())->setTimestamp($expires));
    }

    public function pullUrl(StreamRequest $request, StreamProtocol $protocol): PullUrl
    {
        $this->assertSupported($protocol);

        $app = $request->appName ?? $this->config->defaultAppName;
        $expires = $this->resolveExpiresTimestamp($request->expiresAt);

        $url = $this->signer->buildUrl(
            $protocol->scheme(),
            $this->config->pullDomain,
            $app,
            $request->streamName,
            $protocol->extension(),
            $this->config->pullKey,
            $expires,
            $request->params,
        );

        return new PullUrl($url, $protocol, (new DateTimeImmutable())->setTimestamp($expires));
    }

    public function parseWebhook(string $payload, array $headers = []): LiveEvent
    {
        if ($this->config->callbackKey === '') {
            throw ConfigurationException::missing('huawei.callbackKey');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!\is_array($decoded)) {
            throw InvalidWebhookException::malformed('非合法 JSON');
        }
        /** @var array<string, mixed> $data */
        $data = $decoded;

        $this->verifySignature($data);
        WebhookGuard::assertFresh($data, $this->clock->now()->getTimestamp(), $this->webhookMaxAgeSeconds);

        $streamName = FieldExtractor::stringField($data, 'stream', FieldExtractor::stringField($data, 'streamname'));
        $appName = FieldExtractor::stringField($data, 'app', $this->config->defaultAppName);
        $occurredAt = $this->clock->now();
        $eventType = FieldExtractor::intField($data, 'event_type');

        return match ($eventType) {
            1 => new StreamStartedEvent(Platform::Huawei, $streamName, $occurredAt, $data),
            0 => new StreamEndedEvent(Platform::Huawei, $streamName, $occurredAt, $data),
            100 => new RecordingReadyEvent(
                Platform::Huawei,
                $streamName,
                $occurredAt,
                $this->buildRecording($streamName, $appName, $data),
                $data,
            ),
            default => new UnknownEvent(Platform::Huawei, $streamName, $occurredAt, $data),
        };
    }

    /**
     * 校验回调签名：sign = md5(callbackKey + t)。
     *
     * @param array<string, mixed> $data
     */
    private function verifySignature(array $data): void
    {
        $sign = FieldExtractor::stringField($data, 'sign');
        $t = FieldExtractor::stringField($data, 't', FieldExtractor::stringField($data, 'timestamp'));
        if ($sign === '' || $t === '') {
            throw InvalidWebhookException::malformed('缺少 sign 或时间戳字段');
        }

        $expected = md5($this->config->callbackKey . $t);
        if (!hash_equals($expected, $sign)) {
            throw InvalidWebhookException::signatureMismatch();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildRecording(string $streamName, string $appName, array $data): Recording
    {
        $videoUrl = FieldExtractor::stringField($data, 'video_url', FieldExtractor::stringField($data, 'file_url'));
        $objectKey = $videoUrl !== ''
            ? ltrim((string) parse_url($videoUrl, \PHP_URL_PATH), '/')
            : $this->recordingConfig->resolveObjectKey($appName, $streamName);

        return new Recording(
            streamName: $streamName,
            appName: $appName,
            bucket: $this->recordingConfig->bucket,
            objectKey: $objectKey,
            format: $this->recordingConfig->format,
            sourceUrl: $videoUrl !== '' ? $videoUrl : null,
            sizeBytes: FieldExtractor::nullableIntField($data, 'file_size'),
            durationSeconds: FieldExtractor::nullableIntField($data, 'duration'),
        );
    }
}
