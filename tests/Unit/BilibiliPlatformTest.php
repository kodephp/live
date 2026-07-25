<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\LiveStreaming\Bilibili\BilibiliConfig;
use Kode\Live\LiveStreaming\Bilibili\BilibiliPlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\InvalidWebhookException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BilibiliPlatform::class)]
final class BilibiliPlatformTest extends TestCase
{
    private const string SECRET = 'bili-secret';

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

    private function makePlatform(): BilibiliPlatform
    {
        return new BilibiliPlatform(
            new BilibiliConfig(callbackSecret: self::SECRET),
            new RecordingConfig(bucket: 'b', region: 'r'),
        );
    }

    public function testPushUrlDefaultDomain(): void
    {
        $url = $this->makePlatform()->pushUrl(new StreamRequest('key123'))->url;

        self::assertSame('rtmp://live-push.bilivideo.com/live-bvc/key123', $url);
    }

    public function testPullFlvUrl(): void
    {
        $pull = $this->makePlatform()->pullUrl(new StreamRequest('key123'), StreamProtocol::Flv);

        self::assertSame('https://live-pull.biliapi.com/live-bvc/key123.flv', $pull->url);
    }

    public function testWebhookLiveStart(): void
    {
        $event = $this->makePlatform()->parseWebhook($this->sign([
            'event' => 'live_start',
            'stream' => 'key123',
            'room_id' => '888',
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

        $platform = new BilibiliPlatform(
            new BilibiliConfig(callbackSecret: ''),
            new RecordingConfig(bucket: 'b', region: 'r'),
        );
        $platform->parseWebhook((string) json_encode(['event' => 'live_start', 'sign' => 'x']));
    }
}
