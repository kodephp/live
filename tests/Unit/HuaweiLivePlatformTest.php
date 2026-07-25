<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\LiveStreaming\Huawei\HuaweiLiveConfig;
use Kode\Live\LiveStreaming\Huawei\HuaweiLivePlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\RecordingReadyEvent;
use Kode\Live\Support\Exception\InvalidWebhookException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HuaweiLivePlatform::class)]
final class HuaweiLivePlatformTest extends TestCase
{
    private const int EXPIRES = 1_700_000_000;

    private function makePlatform(): HuaweiLivePlatform
    {
        $config = new HuaweiLiveConfig(
            pushDomain: 'push.huawei.example.com',
            pullDomain: 'pull.huawei.example.com',
            defaultAppName: 'live',
            pushKey: 'pushkey',
            pullKey: 'pullkey',
            callbackKey: 'cbkey',
        );
        $recording = new RecordingConfig(bucket: 'hw-bucket', region: 'cn-north-4');

        return new HuaweiLivePlatform(
            config: $config,
            recordingConfig: $recording,
            clock: FrozenClock::at(self::EXPIRES - 3600),
        );
    }

    public function testPushUrlIsSignedRtmp(): void
    {
        $url = $this->makePlatform()->pushUrl(new StreamRequest('live001'))->url;

        self::assertStringStartsWith('rtmp://push.huawei.example.com/live/live001?', $url);
        self::assertStringContainsString('auth_key=', $url);
    }

    public function testPullUrlFlvHasExtensionAndAuthKey(): void
    {
        $pull = $this->makePlatform()->pullUrl(new StreamRequest('live001'), StreamProtocol::Flv);

        self::assertSame(StreamProtocol::Flv, $pull->protocol);
        self::assertStringContainsString('/live/live001.flv?', $pull->url);
        self::assertStringContainsString('auth_key=', $pull->url);
    }

    public function testParseWebhookRejectsBadSignature(): void
    {
        $payload = json_encode(['event_type' => 1, 'stream' => 's1', 't' => '123', 'sign' => 'wrong']);
        self::assertIsString($payload);

        $this->expectException(InvalidWebhookException::class);
        $this->makePlatform()->parseWebhook($payload);
    }

    public function testParseWebhookRecordingReady(): void
    {
        $t = (string) (self::EXPIRES - 3600);
        $payload = json_encode([
            'event_type' => 100,
            'stream' => 's1',
            'app' => 'live',
            't' => $t,
            'sign' => md5('cbkey' . $t),
            'video_url' => 'https://hw-bucket.obs.cn-north-4.example.com/live/s1/20260101/120000.mp4',
            'file_size' => 4096,
            'duration' => 120,
        ]);
        self::assertIsString($payload);

        $event = $this->makePlatform()->parseWebhook($payload);

        self::assertInstanceOf(RecordingReadyEvent::class, $event);
        self::assertSame(EventType::RecordingReady, $event->type());
        self::assertSame('hw-bucket', $event->recording->bucket);
        self::assertSame(4096, $event->recording->sizeBytes);
    }

    public function testParseWebhookRejectsReplay(): void
    {
        $t = (string) (self::EXPIRES - 3600 - 3600);
        $payload = json_encode([
            'event_type' => 1,
            'stream' => 's1',
            't' => $t,
            'sign' => md5('cbkey' . $t),
        ]);
        self::assertIsString($payload);

        $this->expectException(InvalidWebhookException::class);
        $this->makePlatform()->parseWebhook($payload);
    }
}
