<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Transporter\Exception\TransportException;
use Kode\Live\Transporter\SrtUrl;
use Kode\Live\Transporter\TransporterManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransporterManager::class)]
final class TransporterManagerTest extends TestCase
{
    public function testDefaultRegistersSrtTransporter(): void
    {
        $manager = TransporterManager::default();

        self::assertSame(['srt'], $manager->registered());
        self::assertTrue($manager->has('srt'));
    }

    public function testTransmitRoutesToRegisteredTransporter(): void
    {
        $fake = new FakeTransporter();
        $manager = (new TransporterManager())->register($fake);

        $dest = SrtUrl::fromString('srt://example.com:9000?mode=caller');
        $result = $manager->transmit('udp://0.0.0.0:1234', $dest);

        self::assertTrue($result->success);
        self::assertCount(1, $fake->calls);
        self::assertSame('udp://0.0.0.0:1234', $fake->calls[0]['source']);
        self::assertSame((string) $dest, $fake->calls[0]['destination']);
    }

    public function testUnsupportedSchemeThrows(): void
    {
        $manager = new TransporterManager();
        $this->expectException(TransportException::class);
        $manager->transmit('x', SrtUrl::fromString('srt://h:9000'));
    }
}
