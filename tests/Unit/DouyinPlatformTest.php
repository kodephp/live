<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\LiveStreaming\Douyin\DouyinConfig;
use Kode\Live\LiveStreaming\Douyin\DouyinPlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\InvalidWebhookException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DouyinPlatform::class)]
final class DouyinPlatformTest extends TestCase
{
    private const string SECRET = 'douyin-secret';

    /**
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

    private function makePlatform(): DouyinPlatform
    {
        return new DouyinPlatform(
            new DouyinConfig(callbackSecret: self::SECRET),
            new RecordingConfig(bucket: 'b', region: 'r'),
        );
    }

    public function testPushUrlDefaultDomain(): void
    {
        $url = $this->makePlatform()->pushUrl(new StreamRequest('key123'))->url;

        self::assertSame('rtmp://push-rtmp.douyincdn.com/thirdparty/key123', $url);
    }

    public function testPullHlsUrl(): void
    {
        $pull = $this->makePlatform()->pullUrl(new StreamRequest('key123'), StreamProtocol::Hls);

        self::assertSame('https://pull-flv.douyincdn.com/thirdparty/key123.m3u8', $pull->url);
    }

    public function testWebhookLiveStart(): void
    {
        $event = $this->makePlatform()->parseWebhook($this->sign([
            'event' => 'live_started',
            'stream' => 'key123',
        ]));

        self::assertInstanceOf(StreamStartedEvent::class, $event);
        self::assertSame(EventType::StreamStarted, $event->type());
    }

    public function testWebhookRejectsBadSign(): void
    {
        $this->expectException(InvalidWebhookException::class);

        $payload = (string) json_encode(['event' => 'live_started', 'stream' => 'k', 'sign' => 'bad']);
        $this->makePlatform()->parseWebhook($payload);
    }

    public function testWebhookRequiresSecretConfigured(): void
    {
        $this->expectException(ConfigurationException::class);

        $platform = new DouyinPlatform(
            new DouyinConfig(callbackSecret: ''),
            new RecordingConfig(bucket: 'b', region: 'r'),
        );
        $platform->parseWebhook((string) json_encode(['event' => 'live_started', 'sign' => 'x']));
    }
}
