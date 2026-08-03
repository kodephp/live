<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\LiveStreaming\Cloudflare\CloudflareConfig;
use Kode\Live\LiveStreaming\Cloudflare\CloudflarePlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\InvalidWebhookException;
use Kode\Live\Support\Exception\UnsupportedFeatureException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CloudflarePlatform::class)]
final class CloudflarePlatformTest extends TestCase
{
    private const string SECRET = 'cf-secret';

    /**
     * 按本驱动约定生成带 sign 的回调 payload。
     *
     * @param array<string, mixed> $params
     */
    private function sign(array $params): string
    {
        $data = $params;
        unset($data['sign']);
        ksort($data);

        $raw = '';
        foreach ($data as $key => $value) {
            $raw .= $key . '=' . (string) $value . '&';
        }
        $raw .= 'secret=' . self::SECRET;

        $params['sign'] = md5($raw);

        return (string) json_encode($params);
    }

    private function makePlatform(): CloudflarePlatform
    {
        return new CloudflarePlatform(
            new CloudflareConfig(callbackSecret: self::SECRET),
            new RecordingConfig(bucket: 'b', region: 'r'),
        );
    }

    public function testPushUrlUsesRtmps(): void
    {
        $url = $this->makePlatform()->pushUrl(new StreamRequest('input-key-123'))->url;

        self::assertSame('rtmps://live.cloudflare.com:443/live/input-key-123', $url);
    }

    public function testPullHlsUrlWithManifest(): void
    {
        $pull = $this->makePlatform()->pullUrl(new StreamRequest('vid-123'), StreamProtocol::Hls);

        self::assertSame('https://customer-<code>.cloudflarestream.com/vid-123/manifest.m3u8', $pull->url);
    }

    public function testPullFlvIsUnsupported(): void
    {
        $this->expectException(UnsupportedFeatureException::class);

        $this->makePlatform()->pullUrl(new StreamRequest('vid-123'), StreamProtocol::Flv);
    }

    public function testWebhookStreamStart(): void
    {
        $event = $this->makePlatform()->parseWebhook($this->sign([
            'event' => 'live_input_stream_start',
            'stream_id' => 'vid-123',
        ]));

        self::assertInstanceOf(StreamStartedEvent::class, $event);
        self::assertSame(EventType::StreamStarted, $event->type());
    }

    public function testWebhookRejectsBadSign(): void
    {
        $this->expectException(InvalidWebhookException::class);

        $payload = (string) json_encode(['event' => 'live_input_stream_start', 'stream_id' => 'v', 'sign' => 'bad']);
        $this->makePlatform()->parseWebhook($payload);
    }

    public function testWebhookRequiresSecretConfigured(): void
    {
        $this->expectException(ConfigurationException::class);

        $platform = new CloudflarePlatform(
            new CloudflareConfig(callbackSecret: ''),
            new RecordingConfig(bucket: 'b', region: 'r'),
        );
        $platform->parseWebhook((string) json_encode(['event' => 'live_input_stream_start', 'sign' => 'x']));
    }

    public function testWebhookRejectsReplay(): void
    {
        $clock = FrozenClock::at(1_700_000_000);
        $platform = new CloudflarePlatform(
            new CloudflareConfig(callbackSecret: self::SECRET),
            new RecordingConfig(bucket: 'b', region: 'r'),
            clock: $clock,
            webhookMaxAgeSeconds: 300,
        );

        $payload = $this->sign(['event' => 'live_input_stream_start', 'stream_id' => 'v', 't' => 1_700_000_000 - 3600]);

        $this->expectException(InvalidWebhookException::class);
        $platform->parseWebhook($payload);
    }
}
