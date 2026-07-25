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
    ) {
    }
}
