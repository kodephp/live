<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kode\Live\Download\ResumableDownloader;
use Kode\Live\Support\Dto\DownloadOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResumableDownloader::class)]
final class ResumableDownloaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/kode-live-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        $this->tmpDir = $dir;
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        @rmdir($this->tmpDir);
    }

    public function testFullDownloadWritesFile(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'video/mp4'], 'hello-video'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $downloader = new ResumableDownloader($client);

        $dest = $this->tmpDir . '/out.mp4';
        $result = $downloader->download('https://example.com/a.mp4', $dest, new DownloadOptions(resume: false));

        self::assertFileExists($dest);
        self::assertSame('hello-video', file_get_contents($dest));
        self::assertSame(11, $result->bytes);
        self::assertFalse($result->resumed);
        self::assertSame('video/mp4', $result->contentType);
    }

    public function testResumeAppendsFromExistingBytes(): void
    {
        $dest = $this->tmpDir . '/out.mp4';
        file_put_contents($dest, 'hello-'); // 6 bytes already present

        $mock = new MockHandler([
            new Response(206, ['Content-Range' => 'bytes 6-10/11'], 'video'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $downloader = new ResumableDownloader($client);

        $result = $downloader->download('https://example.com/a.mp4', $dest, new DownloadOptions(resume: true));

        self::assertTrue($result->resumed);
        self::assertSame('hello-video', file_get_contents($dest));
        self::assertSame(11, $result->bytes);
    }
}
