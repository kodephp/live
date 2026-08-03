<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use DateTimeImmutable;
use Kode\Live\Contracts\Downloader;
use Kode\Live\Contracts\LiveEvent;
use Kode\Live\Contracts\LivePlatform;
use Kode\Live\Pipeline\LivePipeline;
use Kode\Live\Support\Archive\TemplateArchiveStrategy;
use Kode\Live\Support\Dto\DownloadOptions;
use Kode\Live\Support\Dto\DownloadResult;
use Kode\Live\Support\Dto\PlaybackUrl;
use Kode\Live\Support\Dto\PullUrl;
use Kode\Live\Support\Dto\PushUrl;
use Kode\Live\Support\Dto\Recording;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Enum\RecordingFormat;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Event\RecordingReadyEvent;
use Kode\Live\Support\Event\StreamStartedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

#[CoversClass(LivePipeline::class)]
final class LivePipelineTest extends TestCase
{
    public function testListenerIsInvokedForMatchingEvent(): void
    {
        $event = new StreamStartedEvent(Platform::Rtmp, 's1', new DateTimeImmutable());
        $pipeline = new LivePipeline(new StubPlatform($event));

        $captured = null;
        $pipeline->on(StreamStartedEvent::class, static function (LiveEvent $e) use (&$captured): void {
            $captured = $e;
        });

        $result = $pipeline->handleWebhook('payload');

        self::assertSame($event, $result);
        self::assertSame($event, $captured);
    }

    public function testPriorityOrderingDescending(): void
    {
        $event = new StreamStartedEvent(Platform::Rtmp, 's1', new DateTimeImmutable());
        $pipeline = new LivePipeline(new StubPlatform($event));

        $order = [];
        $pipeline->on(StreamStartedEvent::class, static function () use (&$order): void {
            $order[] = 'low';
        }, 0);
        $pipeline->on(StreamStartedEvent::class, static function () use (&$order): void {
            $order[] = 'high';
        }, 100);

        $pipeline->handleWebhook('payload');

        self::assertSame(['high', 'low'], $order);
    }

    public function testListenerExceptionDoesNotBreakPipeline(): void
    {
        $event = new StreamStartedEvent(Platform::Rtmp, 's1', new DateTimeImmutable());
        $pipeline = new LivePipeline(new StubPlatform($event));

        $pipeline->on(StreamStartedEvent::class, static function (): void {
            throw new \RuntimeException('boom');
        });
        $safeCalled = false;
        $pipeline->on(StreamStartedEvent::class, static function () use (&$safeCalled): void {
            $safeCalled = true;
        });

        // 不应抛异常：失败监听器被记录后跳过，后续监听器照常执行。
        $pipeline->handleWebhook('payload');

        self::assertTrue($safeCalled);
    }

    public function testPsr14DispatcherIsInvoked(): void
    {
        $event = new StreamStartedEvent(Platform::Rtmp, 's1', new DateTimeImmutable());
        $dispatcher = new FakeDispatcher();
        $pipeline = new LivePipeline(new StubPlatform($event), dispatcher: $dispatcher);

        $pipeline->handleWebhook('payload');

        self::assertSame($event, $dispatcher->last);
    }

    public function testPlaybackForDelegatesToPlatform(): void
    {
        $recording = new Recording(
            streamName: 's1',
            appName: 'live',
            bucket: 'b',
            objectKey: 'o.mp4',
            format: RecordingFormat::Mp4,
            sourceUrl: 'https://x/o.mp4',
        );
        $event = new RecordingReadyEvent(Platform::Rtmp, 's1', new DateTimeImmutable(), $recording);
        $pipeline = new LivePipeline(new StubPlatform($event));

        $url = $pipeline->playbackFor($event);

        self::assertSame('https://example.com/playback', $url->url);
    }

    public function testArchiveUsesDownloader(): void
    {
        $recording = new Recording(
            streamName: 's1',
            appName: 'live',
            bucket: 'b',
            objectKey: 'o.mp4',
            format: RecordingFormat::Mp4,
            sourceUrl: 'https://x/o.mp4',
        );
        $event = new RecordingReadyEvent(Platform::Rtmp, 's1', new DateTimeImmutable(), $recording);
        $downloader = new FakeDownloader();
        $pipeline = new LivePipeline(new StubPlatform($event), downloader: $downloader);

        $result = $pipeline->archive($event, '/tmp/out.mp4');

        self::assertSame('/tmp/out.mp4', $result->path);
    }

    public function testListenerExceptionIsCapturedInDeadLetterQueue(): void
    {
        $event = new StreamStartedEvent(Platform::Rtmp, 's1', new DateTimeImmutable());
        $pipeline = new LivePipeline(new StubPlatform($event));

        $pipeline->on(StreamStartedEvent::class, static function (): void {
            throw new \RuntimeException('boom');
        });
        $pipeline->handleWebhook('payload');

        self::assertSame(1, $pipeline->deadLetters()->count());
        $item = $pipeline->deadLetters()->all()[0];
        self::assertInstanceOf(\RuntimeException::class, $item['error']);
        self::assertSame($event, $item['event']);
    }

    public function testDrainDeadLettersClearsQueue(): void
    {
        $event = new StreamStartedEvent(Platform::Rtmp, 's1', new DateTimeImmutable());
        $pipeline = new LivePipeline(new StubPlatform($event));
        $pipeline->on(StreamStartedEvent::class, static function (): void {
            throw new \RuntimeException('boom');
        });
        $pipeline->handleWebhook('payload');

        $drained = $pipeline->drainDeadLetters();

        self::assertCount(1, $drained);
        self::assertSame(0, $pipeline->deadLetters()->count());
    }

    public function testAutoArchiveInvokesDownloaderOnRecordingReady(): void
    {
        $recording = new Recording(
            streamName: 's1',
            appName: 'live',
            bucket: 'b',
            objectKey: 'records/s1/2026/07/25/s1.mp4',
            format: RecordingFormat::Mp4,
            sourceUrl: 'https://x/s1.mp4',
        );
        $event = new RecordingReadyEvent(Platform::Rtmp, 's1', new DateTimeImmutable(), $recording);
        $downloader = new FakeDownloader();
        $pipeline = new LivePipeline(new StubPlatform($event), downloader: $downloader);
        // 冻结时钟，使 {date} 占位符可确定性断言，避免随真实日期漂移。
        $pipeline->autoArchive(new TemplateArchiveStrategy(
            '/data/{date}/{streamName}/{baseName}',
            new FrozenClock(new DateTimeImmutable('2026-07-25 12:00:00')),
        ));

        $pipeline->handleWebhook('payload');

        // 自动归档应把下载器指向模板生成的路径，且不产生死信。
        self::assertSame('/data/2026/07/25/s1/s1.mp4', $downloader->lastDestination);
        self::assertSame(0, $pipeline->deadLetters()->count());
    }

    public function testAutoArchiveFailureGoesToDeadLetterQueue(): void
    {
        $recording = new Recording(
            streamName: 's1',
            appName: 'live',
            bucket: 'b',
            objectKey: 'o.mp4',
            format: RecordingFormat::Mp4,
            sourceUrl: 'https://x/o.mp4',
        );
        $event = new RecordingReadyEvent(Platform::Rtmp, 's1', new DateTimeImmutable(), $recording);
        // 故意让下载器抛错
        $downloader = new FailingDownloader();
        $pipeline = new LivePipeline(new StubPlatform($event), downloader: $downloader);
        $pipeline->autoArchive(new TemplateArchiveStrategy('/data/{baseName}'));

        $pipeline->handleWebhook('payload');

        self::assertSame(1, $pipeline->deadLetters()->count());
        self::assertInstanceOf(\RuntimeException::class, $pipeline->deadLetters()->all()[0]['error']);
    }
}

/**
 * 测试替身：parseWebhook 直接返回预置事件，其余方法抛错（不被测试触达）。
 */
final class StubPlatform implements LivePlatform
{
    public function __construct(private readonly LiveEvent $event)
    {
    }

    public function name(): Platform
    {
        return Platform::Rtmp;
    }

    public function supports(StreamProtocol $protocol): bool
    {
        return false;
    }

    public function pushUrl(StreamRequest $request): PushUrl
    {
        throw new \LogicException('not used');
    }

    public function pullUrl(StreamRequest $request, StreamProtocol $protocol): PullUrl
    {
        throw new \LogicException('not used');
    }

    public function recordingConfig(): RecordingConfig
    {
        return new RecordingConfig(bucket: 'b', region: 'r');
    }

    public function playbackUrl(Recording $recording, int $ttlSeconds = 3600): PlaybackUrl
    {
        return new PlaybackUrl('https://example.com/playback');
    }

    public function parseWebhook(string $payload, array $headers = []): LiveEvent
    {
        return $this->event;
    }
}

final class FakeDispatcher implements EventDispatcherInterface
{
    public ?object $last = null;

    public function dispatch(object $event): object
    {
        $this->last = $event;

        return $event;
    }
}

final class FakeDownloader implements Downloader
{
    public ?string $lastDestination = null;

    public function download(string $sourceUrl, string $destination, ?DownloadOptions $options = null): DownloadResult
    {
        $this->lastDestination = $destination;

        return new DownloadResult($destination, 0, false, null);
    }
}

final class FailingDownloader implements Downloader
{
    public function download(string $sourceUrl, string $destination, ?DownloadOptions $options = null): DownloadResult
    {
        throw new \RuntimeException('download failed');
    }
}
