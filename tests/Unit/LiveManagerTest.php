<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Contracts\SignedUrlProvider;
use Kode\Live\LiveManager;
use Kode\Live\LiveStreaming\Tencent\TencentCssConfig;
use Kode\Live\LiveStreaming\Tencent\TencentCssPlatform;
use Kode\Live\Pipeline\LivePipeline;
use Kode\Live\Support\Dto\PlaybackUrl;
use Kode\Live\Support\Dto\Recording;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Enum\RecordingFormat;
use Kode\Live\Support\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiveManager::class)]
final class LiveManagerTest extends TestCase
{
    private function tencent(): TencentCssPlatform
    {
        return new TencentCssPlatform(
            new TencentCssConfig('push.example.com', 'pull.example.com'),
            new RecordingConfig('b', 'ap-guangzhou'),
        );
    }

    public function testRegisterAndResolve(): void
    {
        $manager = new LiveManager();
        $manager->register($this->tencent());

        self::assertTrue($manager->has(Platform::TencentCss));
        self::assertSame(Platform::TencentCss, $manager->driver(Platform::TencentCss)->name());
    }

    public function testLazyFactoryResolvesOnce(): void
    {
        $manager = new LiveManager();
        $calls = 0;
        $manager->extend(Platform::TencentCss, function () use (&$calls) {
            ++$calls;

            return $this->tencent();
        });

        $manager->driver(Platform::TencentCss);
        $manager->driver(Platform::TencentCss);

        self::assertSame(1, $calls);
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        (new LiveManager())->driver(Platform::AliyunLive);
    }

    public function testPlaybackFallsBackToSharedProvider(): void
    {
        // 驱动未注入 SignedUrlProvider 时，playback() 应回退到管理器的共享 provider。
        $manager = (new LiveManager())->withProvider(new FakeProvider());
        $manager->register($this->tencent());

        $recording = new Recording(
            streamName: 's1',
            appName: 'live',
            bucket: 'b',
            objectKey: 'rec/s1.mp4',
            format: RecordingFormat::Mp4,
        );

        $playback = $manager->playback(Platform::TencentCss, $recording);

        self::assertInstanceOf(PlaybackUrl::class, $playback);
        self::assertSame('https://sign/b/rec/s1.mp4?ttl=3600', $playback->url);
    }

    public function testPlaybackReturnsNullWhenNoProvider(): void
    {
        $manager = new LiveManager();
        $manager->register($this->tencent());

        $recording = new Recording(
            streamName: 's1',
            appName: 'live',
            bucket: 'b',
            objectKey: 'rec/s1.mp4',
            format: RecordingFormat::Mp4,
        );

        self::assertNull($manager->playback(Platform::TencentCss, $recording));
    }

    public function testPipelineWiresDriver(): void
    {
        $manager = new LiveManager();
        $manager->register($this->tencent());

        $pipeline = $manager->pipeline(Platform::TencentCss);

        self::assertInstanceOf(LivePipeline::class, $pipeline);
    }
}

final class FakeProvider implements SignedUrlProvider
{
    public function presign(string $bucket, string $objectKey, int $ttlSeconds): string
    {
        return \sprintf('https://sign/%s/%s?ttl=%d', $bucket, $objectKey, $ttlSeconds);
    }
}
