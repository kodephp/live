<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Transporter\Enum\SrtMode;
use Kode\Live\Transporter\Exception\TransportException;
use Kode\Live\Transporter\SrtUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SrtUrl::class)]
final class SrtUrlTest extends TestCase
{
    public function testFromStringParssesQueryAndDefaults(): void
    {
        $url = SrtUrl::fromString('srt://ingest.example.com:9000?streamid=room-1&passphrase=secret&latency=200&mode=listener&pbkeylen=16');

        self::assertSame('ingest.example.com', $url->host);
        self::assertSame(9000, $url->port);
        self::assertSame('room-1', $url->streamId);
        self::assertSame('secret', $url->passphrase);
        self::assertSame(200, $url->latencyMs);
        self::assertSame(SrtMode::Listener, $url->mode);
        self::assertSame(16, $url->pbkeylen);
        self::assertSame('srt', $url->scheme());
    }

    public function testRoundTripSerializationIsStable(): void
    {
        $raw = 'srt://host.local:10000?streamid=a&passphrase=p&latency=150&mode=caller&pbkeylen=32';
        self::assertSame($raw, (string) SrtUrl::fromString($raw));
    }

    public function testSerializationIncludesOnlySetFieldsAndFixedOrder(): void
    {
        $url = new SrtUrl(host: '127.0.0.1', port: 9000, mode: SrtMode::Caller);
        self::assertSame('srt://127.0.0.1:9000?mode=caller', (string) $url);
    }

    public function testRejectsNonSrtScheme(): void
    {
        $this->expectException(TransportException::class);
        SrtUrl::fromString('rtmp://host:1935/live/key');
    }

    public function testRejectsBadHost(): void
    {
        $this->expectException(TransportException::class);
        new SrtUrl(host: 'not a host!', port: 9000);
    }

    public function testRejectsOutOfRangePort(): void
    {
        $this->expectException(TransportException::class);
        new SrtUrl(host: 'host', port: 70000);
    }

    public function testInvalidModeFallsBackToCaller(): void
    {
        $url = SrtUrl::fromString('srt://h:9000?mode=bogus');
        self::assertSame(SrtMode::Caller, $url->mode);
    }
}
