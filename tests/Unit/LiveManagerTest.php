<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\LiveManager;
use Kode\Live\LiveStreaming\Tencent\TencentCssConfig;
use Kode\Live\LiveStreaming\Tencent\TencentCssPlatform;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Enum\Platform;
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
}
