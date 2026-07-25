<?php

declare(strict_types=1);

namespace Kode\Live\Contracts;

use Kode\Live\Support\Dto\DownloadOptions;
use Kode\Live\Support\Dto\DownloadResult;

/**
 * 文件下载器契约（用于把录制文件从对象存储取回本地）。
 */
interface Downloader
{
    /**
     * @param string $sourceUrl 源文件 URL（可为对象存储签名直链）
     * @param string $destination 本地目标绝对路径
     * @param DownloadOptions|null $options 下载选项；为空时用默认值
     */
    public function download(string $sourceUrl, string $destination, ?DownloadOptions $options = null): DownloadResult;
}
