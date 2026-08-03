<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Transporter\Exception\TransportException;
use Kode\Live\Transporter\SocketTransporter;
use Kode\Live\Transporter\SrtUrl;
use Kode\Live\Transporter\Transporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SocketTransporter::class)]
final class SocketTransporterTest extends TestCase
{
    public function testSchemeIsSrt(): void
    {
        self::assertSame('srt', (new SocketTransporter())->scheme());
    }

    public function testImplementsTransporter(): void
    {
        self::assertInstanceOf(Transporter::class, new SocketTransporter());
    }

    public function testTransmitThrowsWhenExtSrtMissing(): void
    {
        // 本环境未加载 ext-srt，transmit() 应抛出清晰的 TransportException 而非静默失败。
        if (\extension_loaded('srt')) {
            self::markTestSkipped('ext-srt 已加载，跳过"缺失即抛错"用例');
        }

        $this->expectException(TransportException::class);

        (new SocketTransporter())->transmit(
            'udp://0.0.0.0:1234',
            SrtUrl::fromString('srt://127.0.0.1:9000?mode=caller'),
        );
    }

    public function testAcceptsCustomSocketOptions(): void
    {
        $transporter = new SocketTransporter([99 => 'custom']);

        self::assertSame('srt', $transporter->scheme());
    }
}
