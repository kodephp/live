<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\LiveStreaming\Qiniu\QiniuConfig;
use Kode\Live\LiveStreaming\Qiniu\QiniuPlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\InvalidWebhookException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QiniuPlatform::class)]
final class QiniuPlatformTest extends TestCase
{
    private const string SECRET = 'qiniu-secret';

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

    private function makePlatform(): QiniuPlatform
    {
        return new QiniuPlatform(
            new QiniuConfig(hub: 'myhub', callbackSecret: self::SECRET),
            new RecordingConfig(bucket: 'b', region: 'r'),
        );
    }

    public function testPushUrlUsesHubAsAppName(): void
    {
        $url = $this->makePlatform()->pushUrl(new StreamRequest('key123'))->url;

        self::assertSame('rtmp://pili-publish.qiniu.com/myhub/key123', $url);
    }

    public function testPullHlsUrl(): void
    {
        $pull = $this->makePlatform()->pullUrl(new StreamRequest('key123'), StreamProtocol::Hls);

        self::assertSame('https://pili-live-hls.qiniu.com/myhub/key123.m3u8', $pull->url);
    }

    public function testWebhookLiveStart(): void
    {
        $event = $this->makePlatform()->parseWebhook($this->sign([
            'event' => 'live_start',
            'stream' => 'key123',
        ]));

        self::assertInstanceOf(StreamStartedEvent::class, $event);
        self::assertSame(EventType::StreamStarted, $event->type());
    }

    public function testWebhookRejectsBadSign(): void
    {
        $this->expectException(InvalidWebhookException::class);

        $payload = (string) json_encode(['event' => 'live_start', 'stream' => 'k', 'sign' => 'bad']);
        $this->makePlatform()->parseWebhook($payload);
    }

    public function testWebhookRequiresSecretConfigured(): void
    {
        $this->expectException(ConfigurationException::class);

        $platform = new QiniuPlatform(
            new QiniuConfig(hub: 'myhub', callbackSecret: ''),
            new RecordingConfig(bucket: 'b', region: 'r'),
        );
        $platform->parseWebhook((string) json_encode(['event' => 'live_start', 'sign' => 'x']));
    }

    public function testWebhookRejectsReplay(): void
    {
        $clock = FrozenClock::at(1_700_000_000);
        $platform = new QiniuPlatform(
            new QiniuConfig(hub: 'myhub', callbackSecret: self::SECRET),
            new RecordingConfig(bucket: 'b', region: 'r'),
            clock: $clock,
            webhookMaxAgeSeconds: 300,
        );

        $payload = $this->sign(['event' => 'live_start', 'stream' => 'k', 't' => 1_700_000_000 - 3600]);

        $this->expectException(InvalidWebhookException::class);
        $platform->parseWebhook($payload);
    }
}
