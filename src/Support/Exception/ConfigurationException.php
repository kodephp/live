<?php

declare(strict_types=1);

namespace Kode\Live\Support\Exception;

/**
 * 配置缺失或非法（如缺少推流域名、密钥、bucket 等）。
 */
final class ConfigurationException extends LiveException
{
    public static function missing(string $field): self
    {
        return new self(\sprintf('缺少必填配置项：%s', $field));
    }

    public static function invalid(string $field, string $reason): self
    {
        return new self(\sprintf('配置项 %s 非法：%s', $field, $reason));
    }
}
