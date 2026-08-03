<?php

declare(strict_types=1);

namespace Kode\Live\Support\Dto;

/**
 * 下载结果。
 */
final readonly class DownloadResult
{
    public function __construct(
        public string $path,
        public int $bytes,
        public bool $resumed,
        public ?string $contentType = null,
        /** 期望 / 实际总字节数（来自 expectedSize 或响应 Content-Length；未知为 null）。 */
        public ?int $bytesTotal = null,
        /** 本次下载耗时（秒，浮点），含续传前的已有部分；用于吞吐回执。 */
        public ?float $elapsedSeconds = null,
    ) {
    }
}
