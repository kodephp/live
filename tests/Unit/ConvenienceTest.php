<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use DateTimeImmutable;
use Kode\Live\LiveManager;
use Kode\Live\LiveStreaming\Bilibili\BilibiliConfig;
use Kode\Live\LiveStreaming\Bilibili\BilibiliPlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Dto\StreamUrlSet;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\StreamStartedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiveManager::class)]
#[CoversClass(StreamUrlSet::class)]
final class ConvenienceTest extends TestCase
{
    public function testUrlBundleReturnsPushAndSupportedPulls(): void
    {
        $manager = new LiveManager();
        $manager->register(new BilibiliPlatform(
            new BilibiliConfig(),
            new RecordingConfig(bucket: 'b', region: 'r'),
        ));

        $set = $manager->urlBundle(Platform::Bilibili, new StreamRequest('key123'));

        self::assertInstanceOf(StreamUrlSet::class, $set);
        self::assertStringContainsString('rtmp://', $set->push->url);
        self::assertCount(2, $set->pull); // FLV + HLS
        self::assertNotNull($set->pull(StreamProtocol::Flv));
        self::assertNotNull($set->pull(StreamProtocol::Hls));
        self::assertNull($set->pull(StreamProtocol::Rtmp)); // 仅推流协议，不在拉流列表
        self::assertNull($set->playback); // 未提供录制信息
    }

    public function testEventSerializesToArray(): void
    {
        $event = new StreamStartedEvent(
            Platform::Bilibili,
            'k1',
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            ['a' => 1],
        );

        $arr = $event->toArray();

        self::assertSame('bilibili', $arr['platform']);
        self::assertSame('k1', $arr['streamName']);
        self::assertSame(EventType::StreamStarted->value, $arr['type']);
        self::assertSame('2026-01-01T00:00:00+00:00', $arr['occurredAt']);
        self::assertSame(['a' => 1], $arr['raw']);
        self::assertSame($arr, $event->jsonSerialize());
        self::assertSame($arr, json_decode((string) json_encode($event), true));
    }
}
