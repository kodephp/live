<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\DownloadException;
use Kode\Live\Support\Validation\AssertSafe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssertSafe::class)]
final class AssertSafeTest extends TestCase
{
    public function testSafeUrlAllowsPublicHostname(): void
    {
        AssertSafe::safeUrl('https://example.com/a.mp4');
        AssertSafe::safeUrl('http://cdn.example.org/path?x=1');

        self::expectNotToPerformAssertions();
    }

    public function testSafeUrlRejectsLoopback(): void
    {
        $this->expectException(DownloadException::class);

        AssertSafe::safeUrl('http://127.0.0.1/x');
    }

    public function testSafeUrlRejectsMetadataIp(): void
    {
        $this->expectException(DownloadException::class);

        AssertSafe::safeUrl('http://169.254.169.254/latest/meta-data');
    }

    public function testSafeUrlRejectsPrivateRange(): void
    {
        $this->expectException(DownloadException::class);

        AssertSafe::safeUrl('https://10.0.0.5/video.mp4');
    }

    public function testSafeUrlRejectsIpv6Loopback(): void
    {
        $this->expectException(DownloadException::class);

        AssertSafe::safeUrl('http://[::1]/x');
    }

    public function testSafeUrlRejectsNonHttpScheme(): void
    {
        $this->expectException(DownloadException::class);

        AssertSafe::safeUrl('ftp://example.com/x');
    }

    public function testSafeUrlRejectsMalformed(): void
    {
        $this->expectException(DownloadException::class);

        AssertSafe::safeUrl('not-a-url');
    }

    public function testIdentifierAcceptsSafeValue(): void
    {
        AssertSafe::identifier('live_01.A-B', 'stream');

        self::expectNotToPerformAssertions();
    }

    public function testIdentifierRejectsSlash(): void
    {
        $this->expectException(ConfigurationException::class);

        AssertSafe::identifier('a/b', 'stream');
    }

    public function testNoPathTraversalRejectsDotDot(): void
    {
        $this->expectException(DownloadException::class);

        AssertSafe::noPathTraversal('/var/www/../etc/passwd');
    }
}
