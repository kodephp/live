<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Tencent;

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
use Kode\Live\Support\Signature\TencentCssSigner;
use Kode\Live\Support\Validation\WebhookGuard;
use Kode\Live\Support\Webhook\FieldExtractor;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 腾讯云 CSS（云直播）平台驱动。
 *
 * 录制由 CSS 侧按控制台/API 配置自动落 COS，本驱动负责推拉流地址签名、回放地址与回调验签解析。
 */
final class TencentCssPlatform extends AbstractLivePlatform
{
    private readonly TencentCssSigner $signer;

    public function __construct(
        private readonly TencentCssConfig $config,
        RecordingConfig $recordingConfig,
        ?SignedUrlProvider $signedUrlProvider = null,
        ?TencentCssSigner $signer = null,
        ClockInterface $clock = new SystemClock(),
        LoggerInterface $logger = new NullLogger(),
        int $defaultTtlSeconds = 3600,
        int $webhookMaxAgeSeconds = 300,
    ) {
        parent::__construct($recordingConfig, $signedUrlProvider, $clock, $logger, $defaultTtlSeconds, $webhookMaxAgeSeconds);
        $this->signer = $signer ?? new TencentCssSigner();
    }

    #[\Override]
    public function name(): Platform
    {
        return Platform::TencentCss;
    }

    #[\Override]
    public function supports(StreamProtocol $protocol): bool
    {
        return \in_array($protocol, [
            StreamProtocol::Rtmp,
            StreamProtocol::Flv,
            StreamProtocol::Hls,
            StreamProtocol::WebRtc,
        ], true);
    }

    #[\Override]
    public function pushUrl(StreamRequest $request): PushUrl
    {
        $app = $request->appName ?? $this->config->defaultAppName;
        $expires = $this->resolveExpiresTimestamp($request->expiresAt);

        $url = $this->signer->buildUrl(
            StreamProtocol::Rtmp->scheme(),
            $this->config->pushDomain,
            $app,
            $request->streamName,
            $request->streamName,
            $this->config->pushKey,
            $expires,
            $request->params,
        );

        return new PushUrl($url, StreamProtocol::Rtmp, (new DateTimeImmutable())->setTimestamp($expires));
    }

    #[\Override]
    public function pullUrl(StreamRequest $request, StreamProtocol $protocol): PullUrl
    {
        $this->assertSupported($protocol);

        $app = $request->appName ?? $this->config->defaultAppName;
        $expires = $this->resolveExpiresTimestamp($request->expiresAt);
        $pathStream = $request->streamName . $protocol->extension();

        $url = $this->signer->buildUrl(
            $protocol->scheme(),
            $this->config->pullDomain,
            $app,
            $pathStream,
            $request->streamName,
            $this->config->pullKey,
            $expires,
            $request->params,
        );

        return new PullUrl($url, $protocol, (new DateTimeImmutable())->setTimestamp($expires));
    }

    #[\Override]
    public function parseWebhook(string $payload, array $headers = []): LiveEvent
    {
        if ($this->config->callbackKey === '') {
            throw ConfigurationException::missing('tencent.callbackKey');
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

        $streamName = FieldExtractor::stringField($data, 'stream_id');
        $appName = FieldExtractor::stringField($data, 'appname', $this->config->defaultAppName);
        $occurredAt = $this->clock->now();
        $eventType = FieldExtractor::intField($data, 'event_type');

        return match ($eventType) {
            1 => new StreamStartedEvent(Platform::TencentCss, $streamName, $occurredAt, $data),
            0 => new StreamEndedEvent(Platform::TencentCss, $streamName, $occurredAt, $data),
            100 => new RecordingReadyEvent(
                Platform::TencentCss,
                $streamName,
                $occurredAt,
                $this->buildRecording($streamName, $appName, $data),
                $data,
            ),
            default => new UnknownEvent(Platform::TencentCss, $streamName, $occurredAt, $data),
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
        $t = FieldExtractor::stringField($data, 't');
        if ($sign === '' || $t === '') {
            throw InvalidWebhookException::malformed('缺少 sign 或 t 字段');
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
        $videoUrl = FieldExtractor::stringField($data, 'video_url');
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
