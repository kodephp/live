<?php

declare(strict_types=1);

namespace Kode\Live\Download;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Kode\Live\Contracts\Downloader;
use Kode\Live\Support\Dto\DownloadOptions;
use Kode\Live\Support\Dto\DownloadResult;
use Kode\Live\Support\Exception\DownloadException;
use Kode\Live\Support\Http\GuzzleClientFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 基于 HTTP Range 的断点续传下载器。
 *
 * 从对象存储直链 / 签名 URL 把录制文件流式写回本地，支持断点续传与失败重试。
 */
final class ResumableDownloader implements Downloader
{
    public function __construct(
        private readonly ClientInterface $client = new \GuzzleHttp\Client(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function default(LoggerInterface $logger = new NullLogger()): self
    {
        return new self(GuzzleClientFactory::create(), $logger);
    }

    public function download(string $sourceUrl, string $destination, ?DownloadOptions $options = null): DownloadResult
    {
        $options ??= new DownloadOptions();
        $this->assertSafeDestination($destination);

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $options->maxRetries) {
            try {
                return $this->attempt($sourceUrl, $destination, $options);
            } catch (GuzzleException $e) {
                $lastError = $e;
                ++$attempt;
                $this->logger->warning('下载失败，准备重试', [
                    'attempt' => $attempt,
                    'max' => $options->maxRetries,
                ]);
            }
        }

        throw DownloadException::transfer(
            $lastError instanceof \Throwable ? $lastError->getMessage() : '未知错误',
            $lastError,
        );
    }

    /**
     * @throws GuzzleException
     */
    private function attempt(string $sourceUrl, string $destination, DownloadOptions $options): DownloadResult
    {
        $offset = 0;
        if ($options->resume && is_file($destination)) {
            $size = filesize($destination);
            $offset = $size !== false ? $size : 0;
        }

        $headers = $options->headers;
        if ($offset > 0) {
            $headers['Range'] = \sprintf('bytes=%d-', $offset);
        }

        $response = $this->client->request('GET', $sourceUrl, [
            'stream' => true,
            'headers' => $headers,
            'timeout' => $options->timeout,
            'connect_timeout' => $options->connectTimeout,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();

        // 已完整下载：服务端返回 416 表示请求范围超出文件大小。
        if ($status === 416 && $offset > 0) {
            return new DownloadResult($destination, $offset, true, $this->contentType($response));
        }

        if ($status >= 400) {
            throw DownloadException::transfer(\sprintf('HTTP 状态码 %d', $status));
        }

        // 断点续传：206 追加写入；否则从头覆盖写入。
        $resumed = $status === 206 && $offset > 0;
        $mode = $resumed ? 'ab' : 'wb';
        $written = $resumed ? $offset : 0;

        $handle = fopen($destination, $mode);
        if ($handle === false) {
            throw DownloadException::notWritable($destination);
        }

        try {
            $body = $response->getBody();
            while (!$body->eof()) {
                $chunk = $body->read($options->chunkBytes);
                if ($chunk === '') {
                    break;
                }
                $bytes = fwrite($handle, $chunk);
                if ($bytes === false) {
                    throw DownloadException::transfer('写入本地文件失败');
                }
                $written += $bytes;
            }
        } finally {
            fclose($handle);
        }

        return new DownloadResult($destination, $written, $resumed, $this->contentType($response));
    }

    private function contentType(\Psr\Http\Message\ResponseInterface $response): ?string
    {
        $header = $response->getHeaderLine('Content-Type');

        return $header !== '' ? $header : null;
    }

    private function assertSafeDestination(string $destination): void
    {
        if ($destination === '' || str_contains($destination, "\0")) {
            throw DownloadException::unsafePath($destination);
        }

        $dir = \dirname($destination);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw DownloadException::notWritable($dir);
            }
        }
        if (!is_writable($dir)) {
            throw DownloadException::notWritable($dir);
        }
    }
}
