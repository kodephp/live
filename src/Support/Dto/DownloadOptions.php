<?php

declare(strict_types=1);

namespace Kode\Live\Support\Dto;

use Kode\Live\Support\Exception\ConfigurationException;

/**
 * 下载选项。
 */
final readonly class DownloadOptions
{
    /**
     * @param bool $resume 是否启用断点续传（基于 HTTP Range）
     * @param int $chunkBytes 流式写盘的缓冲块大小（字节）
     * @param int $timeout 单次请求总超时（秒）
     * @param int $connectTimeout 连接超时（秒）
     * @param int $maxRetries 失败重试次数
     * @param array<string, string> $headers 追加的请求头
     * @param int|null $expectedSize 期望下载完成后的总字节数；提供则会校验一致性
     * @param string|null $expectedSha256 期望文件的 SHA-256（64 位十六进制）；提供则校验完整性
     */
    public function __construct(
        public bool $resume = true,
        public int $chunkBytes = 1_048_576,
        public int $timeout = 300,
        public int $connectTimeout = 5,
        public int $maxRetries = 2,
        public array $headers = [],
        public ?int $expectedSize = null,
        public ?string $expectedSha256 = null,
    ) {
        if ($chunkBytes <= 0) {
            throw ConfigurationException::invalid('download.chunkBytes', '必须为正数');
        }
        if ($maxRetries < 0) {
            throw ConfigurationException::invalid('download.maxRetries', '不能为负数');
        }
        if ($expectedSize !== null && $expectedSize < 0) {
            throw ConfigurationException::invalid('download.expectedSize', '不能为负数');
        }
        if ($expectedSha256 !== null && !preg_match('/\A[0-9a-fA-F]{64}\z/', $expectedSha256)) {
            throw ConfigurationException::invalid('download.expectedSha256', '必须为 64 位十六进制 SHA-256');
        }
    }
}
