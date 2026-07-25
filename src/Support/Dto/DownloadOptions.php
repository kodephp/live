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
     */
    public function __construct(
        public bool $resume = true,
        public int $chunkBytes = 1_048_576,
        public int $timeout = 300,
        public int $connectTimeout = 5,
        public int $maxRetries = 2,
        public array $headers = [],
    ) {
        if ($chunkBytes <= 0) {
            throw ConfigurationException::invalid('download.chunkBytes', '必须为正数');
        }
        if ($maxRetries < 0) {
            throw ConfigurationException::invalid('download.maxRetries', '不能为负数');
        }
    }
}
