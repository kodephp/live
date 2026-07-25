<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Bilibili;

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
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * B站（哔哩哔哩）直播平台驱动。
 *
 * 推流 RTMP（域名 + live-bvc + 流名），拉流 FLV / HLS；回调验签采用 md5(排序参数 + secret)。
 * 注：开播 / 关播的开放平台 API 调用不在本包范围，请用官方 SDK；
 * 本驱动负责地址生成与回调归一化。
 */
final class BilibiliPlatform extends AbstractStreamKeyPlatform
{
    public function __construct(
        private readonly BilibiliConfig $config,
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
        return Platform::Bilibili;
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
            throw ConfigurationException::missing('bilibili.callbackSecret');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!\is_array($decoded)) {
            throw InvalidWebhookException::malformed('非合法 JSON');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;
        $this->verifySign($data);
        WebhookGuard::assertFresh($data, $this->clock->now()->getTimestamp(), $this->webhookMaxAgeSeconds);

        $event = $this->stringField($data, 'event', $this->stringField($data, 'action'));
        $stream = $this->stringField($data, 'stream', $this->stringField($data, 'room_id'));
        $occurredAt = $this->clock->now();

        return match ($event) {
            'live_start', 'start' => new StreamStartedEvent($this->name(), $stream, $occurredAt, $data),
            'live_end', 'end' => new StreamEndedEvent($this->name(), $stream, $occurredAt, $data),
            default => new UnknownEvent($this->name(), $stream, $occurredAt, $data),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function verifySign(array $data): void
    {
        $sign = $this->stringField($data, 'sign');
        if ($sign === '') {
            throw InvalidWebhookException::malformed('缺少 sign 字段');
        }

        $params = $data;
        unset($params['sign']);
        ksort($params);

        $raw = '';
        foreach ($params as $key => $value) {
            $raw .= $key . '=' . (string) $value . '&';
        }
        $raw .= 'secret=' . $this->config->callbackSecret;

        if (!hash_equals(md5($raw), $sign)) {
            throw InvalidWebhookException::signatureMismatch();
        }
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
