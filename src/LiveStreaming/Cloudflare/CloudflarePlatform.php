<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Cloudflare;

use Kode\Live\Contracts\LiveEvent;
use Kode\Live\Contracts\SignedUrlProvider;
use Kode\Live\LiveStreaming\AbstractStreamKeyPlatform;
use Kode\Live\Support\Clock\SystemClock;
use Kode\Live\Support\Dto\PullUrl;
use Kode\Live\Support\Dto\PushUrl;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\StreamEndedEvent;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Event\UnknownEvent;
use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\InvalidWebhookException;
use Kode\Live\Support\Exception\UnsupportedFeatureException;
use Kode\Live\Support\Validation\WebhookGuard;
use Kode\Live\Support\Webhook\FieldExtractor;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Cloudflare Stream 平台驱动。
 *
 * Cloudflare Live Input 以 RTMPS 摄入（`rtmps://{host}/live/{streamKey}`），播放仅提供 HLS
 * （`https://customer-<code>.cloudflarestream.com/{videoId}/manifest.m3u8`）。由于协议与路径结构
 * 与「RTMP + AppName」基类不同，本驱动在 {@see AbstractStreamKeyPlatform} 基础上覆盖
 * `pushUrl()` / `pullUrl()` / `supports()`，回调验签仍复用通用
 * `md5(排序参数 + secret)` 方案（{@see FieldExtractor::verifySortedMd5}）。
 *
 * 注：Cloudflare 原生回调签名基于 Webhook 签名密钥（HMAC），与本驱动采用的 md5 方案不同；本驱动
 * 做「配置驱动地址拼装 + 回调归一化（md5 方案）」，若需严格校验 Cloudflare 原生签名，请在本类
 * {@see parseWebhook()} 中替换为对应校验，或前置一个签名转换网关。
 */
final class CloudflarePlatform extends AbstractStreamKeyPlatform
{
    public function __construct(
        private readonly CloudflareConfig $config,
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
        return Platform::Cloudflare;
    }

    protected function defaultPushDomain(): string
    {
        return $this->config->pushDomain;
    }

    protected function defaultPullDomain(): string
    {
        return $this->config->pullDomain;
    }

    protected function webhookSecret(): string
    {
        return $this->config->callbackSecret;
    }

    public function supports(StreamProtocol $protocol): bool
    {
        return match ($protocol) {
            StreamProtocol::Rtmp => true,
            StreamProtocol::Hls => $this->pullDomain() !== '',
            default => false,
        };
    }

    public function pushUrl(StreamRequest $request): PushUrl
    {
        $base = \sprintf('rtmps://%s/live/%s', $this->pushDomain(), $request->streamName);

        return new PushUrl($this->appendAuth($base, $request->params), StreamProtocol::Rtmp);
    }

    public function pullUrl(StreamRequest $request, StreamProtocol $protocol): PullUrl
    {
        if ($protocol !== StreamProtocol::Hls) {
            throw UnsupportedFeatureException::protocol($this->name(), $protocol);
        }

        $domain = $this->pullDomain();
        if ($domain === '') {
            throw UnsupportedFeatureException::feature($this->name(), 'HLS 拉流（未配置 pullDomain）');
        }

        $base = \sprintf('https://%s/%s/manifest.m3u8', $domain, $request->streamName);

        return new PullUrl($this->appendAuth($base, $request->params), $protocol);
    }

    public function parseWebhook(string $payload, array $headers = []): LiveEvent
    {
        if ($this->config->callbackSecret === '') {
            throw ConfigurationException::missing('cloudflare.callbackSecret');
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
        $stream = FieldExtractor::stringField($data, 'stream_id', FieldExtractor::stringField($data, 'stream'));
        $occurredAt = $this->clock->now();

        return match ($event) {
            'live_input_stream_start', 'stream_start', 'start' => new StreamStartedEvent($this->name(), $stream, $occurredAt, $data),
            'live_input_stream_end', 'stream_end', 'end' => new StreamEndedEvent($this->name(), $stream, $occurredAt, $data),
            default => new UnknownEvent($this->name(), $stream, $occurredAt, $data),
        };
    }
}
