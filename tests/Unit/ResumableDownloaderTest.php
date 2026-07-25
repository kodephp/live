<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kode\Live\Download\ResumableDownloader;
use Kode\Live\Support\Dto\DownloadOptions;
use Kode\Live\Support\Exception\DownloadException;
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

    public function testDownloadVerifiesSha256AndSize(): void
    {
        $body = 'hello-video';
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'video/mp4'], $body),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $downloader = new ResumableDownloader($client);

        $dest = $this->tmpDir . '/out.mp4';
        $options = new DownloadOptions(
            resume: false,
            expectedSize: \strlen($body),
            expectedSha256: hash('sha256', $body),
        );

        $result = $downloader->download('https://example.com/a.mp4', $dest, $options);

        self::assertFileExists($dest);
        self::assertSame($body, file_get_contents($dest));
        self::assertSame(\strlen($body), $result->bytes);
    }

    public function testDownloadFailsOnShaMismatch(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'hello-video'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $downloader = new ResumableDownloader($client);

        $dest = $this->tmpDir . '/out.mp4';
        $options = new DownloadOptions(resume: false, expectedSha256: str_repeat('a', 64));

        $this->expectException(DownloadException::class);

        $downloader->download('https://example.com/a.mp4', $dest, $options);
    }

    public function testDownloadFailsOnSizeMismatch(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'hello-video'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $downloader = new ResumableDownloader($client);

        $dest = $this->tmpDir . '/out.mp4';
        $options = new DownloadOptions(resume: false, expectedSize: 9999);

        $this->expectException(DownloadException::class);

        $downloader->download('https://example.com/a.mp4', $dest, $options);
    }

    public function testDownloadRejectsPathTraversal(): void
    {
        $downloader = new ResumableDownloader();

        $dest = $this->tmpDir . '/../escape.mp4';

        $this->expectException(DownloadException::class);

        $downloader->download('https://example.com/a.mp4', $dest);
    }
}
