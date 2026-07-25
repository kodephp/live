<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Aliyun;

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
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 阿里云直播平台驱动。
 *
 * 录制由阿里云直播侧配置自动落 OSS，本驱动负责推拉流 URL 鉴权（A 方式）、回放地址与回调解析。
 */
final class AliyunLivePlatform extends AbstractLivePlatform
{
    private readonly AliyunLiveSigner $signer;

    public function __construct(
        private readonly AliyunLiveConfig $config,
        RecordingConfig $recordingConfig,
        ?SignedUrlProvider $signedUrlProvider = null,
        ?AliyunLiveSigner $signer = null,
        ClockInterface $clock = new SystemClock(),
        LoggerInterface $logger = new NullLogger(),
        int $defaultTtlSeconds = 3600,
    ) {
        parent::__construct($recordingConfig, $signedUrlProvider, $clock, $logger, $defaultTtlSeconds);
        $this->signer = $signer ?? new AliyunLiveSigner();
    }

    public function name(): Platform
    {
        return Platform::AliyunLive;
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
        if ($this->config->callbackToken === '') {
            throw ConfigurationException::missing('aliyun.callbackToken');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!\is_array($decoded)) {
            throw InvalidWebhookException::malformed('非合法 JSON');
        }
        /** @var array<string, mixed> $data */
        $data = $decoded;

        $this->verifyToken($data, $headers);

        $streamName = $this->stringField($data, 'id', $this->stringField($data, 'stream'));
        $appName = $this->stringField($data, 'app', $this->config->defaultAppName);
        $occurredAt = $this->clock->now();
        $action = $this->stringField($data, 'action');
        $ossObject = $this->stringField($data, 'oss_object');

        if ($action === 'publish') {
            return new StreamStartedEvent(Platform::AliyunLive, $streamName, $occurredAt, $data);
        }
        if ($action === 'publish_done' || $action === 'unpublish') {
            return new StreamEndedEvent(Platform::AliyunLive, $streamName, $occurredAt, $data);
        }
        if ($action === 'record' || $ossObject !== '') {
            return new RecordingReadyEvent(
                Platform::AliyunLive,
                $streamName,
                $occurredAt,
                $this->buildRecording($streamName, $appName, $data),
                $data,
            );
        }

        return new UnknownEvent(Platform::AliyunLive, $streamName, $occurredAt, $data);
    }

    /**
     * 校验回调来源令牌：优先取 header，其次取 payload 中的 token 字段，与配置常量时间比较。
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function verifyToken(array $data, array $headers): void
    {
        $token = $headers['X-Callback-Token']
            ?? $headers['x-callback-token']
            ?? $this->stringField($data, 'token');

        if (!\is_string($token) || $token === '') {
            throw InvalidWebhookException::malformed('缺少回调令牌 token');
        }

        if (!hash_equals($this->config->callbackToken, $token)) {
            throw InvalidWebhookException::signatureMismatch();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildRecording(string $streamName, string $appName, array $data): Recording
    {
        $bucket = $this->stringField($data, 'oss_bucket', $this->recordingConfig->bucket);
        $ossObject = $this->stringField($data, 'oss_object');
        $url = $this->stringField($data, 'url');
        $objectKey = $ossObject !== ''
            ? $ossObject
            : $this->recordingConfig->resolveObjectKey($appName, $streamName);

        return new Recording(
            streamName: $streamName,
            appName: $appName,
            bucket: $bucket,
            objectKey: $objectKey,
            format: $this->recordingConfig->format,
            sourceUrl: $url !== '' ? $url : null,
            sizeBytes: $this->nullableIntField($data, 'size'),
            durationSeconds: $this->nullableIntField($data, 'duration'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return \is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function nullableIntField(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
