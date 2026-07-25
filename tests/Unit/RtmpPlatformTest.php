<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\LiveStreaming\Rtmp\RtmpConfig;
use Kode\Live\LiveStreaming\Rtmp\RtmpPlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\RecordingReadyEvent;
use Kode\Live\Support\Event\StreamStartedEvent;
use Kode\Live\Support\Exception\InvalidWebhookException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RtmpPlatform::class)]
final class RtmpPlatformTest extends TestCase
{
    private function makePlatform(RtmpConfig $config): RtmpPlatform
    {
        return new RtmpPlatform($config, new RecordingConfig(bucket: 'b', region: 'r'));
    }

    public function testPushUrlBuiltFromConfig(): void
    {
        $url = $this->makePlatform(new RtmpConfig(pushDomain: 'rtmp.example.com', appName: 'app'))
            ->pushUrl(new StreamRequest('stream1'))->url;

        self::assertSame('rtmp://rtmp.example.com/app/stream1', $url);
    }

    public function testPushUrlAppendsAuthKey(): void
    {
        $url = $this->makePlatform(new RtmpConfig(pushDomain: 'rtmp.example.com', authSecret: 'secret'))
            ->pushUrl(new StreamRequest('s1'))->url;

        self::assertSame('rtmp://rtmp.example.com/s1?key=secret', $url);
    }

    public function testPullFlvUrl(): void
    {
        $pull = $this->makePlatform(new RtmpConfig(pushDomain: 'x', pullDomain: 'pull.example.com', appName: 'app'))
            ->pullUrl(new StreamRequest('s1'), StreamProtocol::Flv);

        self::assertSame('https://pull.example.com/app/s1.flv', $pull->url);
        self::assertSame(StreamProtocol::Flv, $pull->protocol);
    }

    public function testWebhookOnPublishStarts(): void
    {
        $event = $this->makePlatform(new RtmpConfig(pushDomain: 'x'))
            ->parseWebhook('action=on_publish&stream=s1&app=app');

        self::assertInstanceOf(StreamStartedEvent::class, $event);
    }

    public function testWebhookOnDvrRecordingReady(): void
    {
        $event = $this->makePlatform(new RtmpConfig(pushDomain: 'x'))
            ->parseWebhook('action=on_dvr&stream=s1&app=app&file=/var/rec/20260101/s1.mp4');

        self::assertInstanceOf(RecordingReadyEvent::class, $event);
        self::assertSame('s1.mp4', $event->recording->objectKey);
    }

    public function testWebhookRejectsBadSecret(): void
    {
        $this->expectException(InvalidWebhookException::class);

        $this->makePlatform(new RtmpConfig(pushDomain: 'x', callbackSecret: 'topsecret'))
            ->parseWebhook('action=on_publish&stream=s1&secret=wrong');
    }
}
