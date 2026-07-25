<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\LiveStreaming\Tencent\TencentCssConfig;
use Kode\Live\LiveStreaming\Tencent\TencentCssPlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\RecordingReadyEvent;
use Kode\Live\Support\Exception\InvalidWebhookException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TencentCssPlatform::class)]
final class TencentCssPlatformTest extends TestCase
{
    private const int EXPIRES = 1_700_000_000;

    private function makePlatform(): TencentCssPlatform
    {
        $config = new TencentCssConfig(
            pushDomain: 'push.example.com',
            pullDomain: 'pull.example.com',
            defaultAppName: 'live',
            pushKey: 'pushkey',
            pullKey: 'pullkey',
            callbackKey: 'cbkey',
        );
        $recording = new RecordingConfig(bucket: 'my-bucket', region: 'ap-guangzhou');

        return new TencentCssPlatform(
            config: $config,
            recordingConfig: $recording,
            clock: FrozenClock::at(self::EXPIRES - 3600),
        );
    }

    public function testPushUrlIsSignedRtmp(): void
    {
        $url = $this->makePlatform()->pushUrl(new StreamRequest('live001'))->url;

        self::assertStringStartsWith('rtmp://push.example.com/live/live001?', $url);
        self::assertStringContainsString('txSecret=', $url);
    }

    public function testPullUrlFlvHasExtension(): void
    {
        $pull = $this->makePlatform()->pullUrl(new StreamRequest('live001'), StreamProtocol::Flv);

        self::assertSame(StreamProtocol::Flv, $pull->protocol);
        self::assertStringContainsString('/live/live001.flv?', $pull->url);
    }

    public function testParseWebhookRejectsBadSignature(): void
    {
        $payload = json_encode(['event_type' => 1, 'stream_id' => 's1', 't' => '123', 'sign' => 'wrong']);
        self::assertIsString($payload);

        $this->expectException(InvalidWebhookException::class);
        $this->makePlatform()->parseWebhook($payload);
    }

    public function testParseWebhookRecordingReady(): void
    {
        $t = '1700000000';
        $payload = json_encode([
            'event_type' => 100,
            'stream_id' => 's1',
            'appname' => 'live',
            't' => $t,
            'sign' => md5('cbkey' . $t),
            'video_url' => 'https://my-bucket.cos.example.com/live/s1/20260101/120000.mp4',
            'file_size' => 2048,
            'duration' => 60,
        ]);
        self::assertIsString($payload);

        $event = $this->makePlatform()->parseWebhook($payload);

        self::assertInstanceOf(RecordingReadyEvent::class, $event);
        self::assertSame(EventType::RecordingReady, $event->type());
        self::assertSame('my-bucket', $event->recording->bucket);
        self::assertSame('live/s1/20260101/120000.mp4', $event->recording->objectKey);
        self::assertSame(2048, $event->recording->sizeBytes);
    }
}
