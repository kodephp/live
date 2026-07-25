<?php

declare(strict_types=1);

namespace Kode\Live\Support\Exception;

use Throwable;

/**
 * 下载过程中的错误（网络失败、写盘失败、路径非法等）。
 */
final class DownloadException extends LiveException
{
    public static function unsafePath(string $path): self
    {
        return new self(\sprintf('目标路径非法或存在穿越风险：%s', $path));
    }

    public static function notWritable(string $path): self
    {
        return new self(\sprintf('目标目录不可写：%s', $path));
    }

    public static function transfer(string $reason, ?Throwable $previous = null): self
    {
        return new self(\sprintf('下载失败：%s', $reason), 0, $previous);
    }
}
