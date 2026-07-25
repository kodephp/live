<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Rtmp;

use Kode\Live\Contracts\LiveEvent;
use Kode\Live\Contracts\SignedUrlProvider;
use Kode\Live\LiveStreaming\AbstractStreamKeyPlatform;
use Kode\Live\Support\Clock\SystemClock;
use Kode\Live\Support\Dto\Recording;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Event\RecordingReadyEvent;
use Kode\Live\Support\Event\StreamEndedEvent;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Event\UnknownEvent;
use Kode\Live\Support\Exception\InvalidWebhookException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 通用 RTMP 平台驱动（自建 SRS / nginx-rtmp）。
 *
 * 推流地址由「域名 + AppName + 流名」拼装；可选 ?key= 鉴权。
 * 录制由 RTMP 服务端（on_dvr / on_record_done 回调）落存储；本驱动解析该回调为 RecordingReadyEvent。
 */
final class RtmpPlatform extends AbstractStreamKeyPlatform
{
    public function __construct(
        private readonly RtmpConfig $config,
        RecordingConfig $recordingConfig,
        ?SignedUrlProvider $signedUrlProvider = null,
        ClockInterface $clock = new SystemClock(),
        LoggerInterface $logger = new NullLogger(),
        int $defaultTtlSeconds = 3600,
    ) {
        parent::__construct($recordingConfig, $signedUrlProvider, $clock, $logger, $defaultTtlSeconds);
    }

    public function name(): Platform
    {
        return Platform::Rtmp;
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

    /**
     * @return array<string, string>
     */
    protected function authParams(): array
    {
        if ($this->config->authSecret === '') {
            return [];
        }

        return [$this->config->authParam => $this->config->authSecret];
    }

    public function parseWebhook(string $payload, array $headers = []): LiveEvent
    {
        /** @var array<int|string, mixed> $parsed */
        $parsed = [];
        parse_str($payload, $parsed);
        if ($parsed === []) {
            throw InvalidWebhookException::malformed('空或非法的表单回调');
        }

        /** @var array<string, mixed> $data */
        $data = [];
        foreach ($parsed as $key => $value) {
            $data[(string) $key] = $value;
        }

        $this->verifyHook($data);

        $action = $this->stringField($data, 'action');
        $stream = $this->stringField($data, 'stream');
        $app = $this->stringField($data, 'app', $this->config->appName);
        $occurredAt = $this->clock->now();

        return match ($action) {
            'on_publish' => new StreamStartedEvent($this->name(), $stream, $occurredAt, $data),
            'on_unpublish', 'on_stop' => new StreamEndedEvent($this->name(), $stream, $occurredAt, $data),
            'on_dvr', 'on_record_done' => new RecordingReadyEvent(
                $this->name(),
                $stream,
                $occurredAt,
                $this->buildRecording($stream, $app, $data),
                $data,
            ),
            default => new UnknownEvent($this->name(), $stream, $occurredAt, $data),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function verifyHook(array $data): void
    {
        if ($this->config->callbackSecret === '') {
            return;
        }

        $provided = $this->stringField($data, 'secret');
        if (!hash_equals($this->config->callbackSecret, $provided)) {
            throw InvalidWebhookException::signatureMismatch();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildRecording(string $stream, string $app, array $data): Recording
    {
        $file = $this->stringField($data, 'file', $this->stringField($data, 'path'));
        $objectKey = $file !== ''
            ? basename($file)
            : $this->recordingConfig->resolveObjectKey($app, $stream);

        return new Recording(
            streamName: $stream,
            appName: $app,
            bucket: $this->recordingConfig->bucket,
            objectKey: $objectKey,
            format: $this->recordingConfig->format,
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
}
