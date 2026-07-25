<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\LiveStreaming\WeixinChannels\WeixinChannelsConfig;
use Kode\Live\LiveStreaming\WeixinChannels\WeixinChannelsPlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\StreamEndedEvent;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Exception\InvalidWebhookException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WeixinChannelsPlatform::class)]
final class WeixinChannelsPlatformTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    private function makePlatform(): WeixinChannelsPlatform
    {
        $config = new WeixinChannelsConfig(
            pushDomain: 'livepush.weixin.qq.com',
            pullDomain: '',
            appName: 'live',
            callbackToken: 'tok',
        );
        $recording = new RecordingConfig(bucket: 'wx-bucket', region: 'ap-guangzhou');

        return new WeixinChannelsPlatform(
            config: $config,
            recordingConfig: $recording,
            clock: FrozenClock::at(self::NOW),
        );
    }

    public function testPushUrlIsRtmp(): void
    {
        $url = $this->makePlatform()->pushUrl(new StreamRequest('stream_001'))->url;

        self::assertStringStartsWith('rtmp://livepush.weixin.qq.com/live/stream_001', $url);
    }

    public function testNoPullDomainMeansUnsupported(): void
    {
        $platform = $this->makePlatform();
        self::assertFalse($platform->supports(StreamProtocol::Flv));
    }

    public function testParseWebhookLiveStarted(): void
    {
        $payload = (string) json_encode([
            'event' => 'live_start',
            'stream' => 'stream_001',
            't' => (string) self::NOW,
            'sign' => $this->sign(['event' => 'live_start', 'stream' => 'stream_001', 't' => (string) self::NOW]),
        ]);
        $event = $this->makePlatform()->parseWebhook($payload);

        self::assertInstanceOf(StreamStartedEvent::class, $event);
        self::assertSame(EventType::StreamStarted, $event->type());
        self::assertSame('stream_001', $event->streamName());
    }

    public function testParseWebhookLiveEnded(): void
    {
        $payload = (string) json_encode([
            'action' => 'push_stop',
            'room_id' => 'room_9',
            'timestamp' => (string) self::NOW,
            'sign' => $this->sign(['action' => 'push_stop', 'room_id' => 'room_9', 'timestamp' => (string) self::NOW]),
        ]);
        $event = $this->makePlatform()->parseWebhook($payload);

        self::assertInstanceOf(StreamEndedEvent::class, $event);
        self::assertSame('room_9', $event->streamName());
    }

    public function testParseWebhookRejectsBadSignature(): void
    {
        $payload = (string) json_encode([
            'event' => 'live_start',
            'stream' => 'stream_001',
            'sign' => 'deadbeef',
        ]);

        $this->expectException(InvalidWebhookException::class);
        $this->makePlatform()->parseWebhook($payload);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function sign(array $params): string
    {
        ksort($params);
        $raw = '';
        foreach ($params as $key => $value) {
            $raw .= $key . '=' . (string) $value . '&';
        }

        return md5($raw . 'secret=' . 'tok');
    }
}
