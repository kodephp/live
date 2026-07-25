<?php

declare(strict_types=1);

namespace Kode\Live\Support\Exception;

use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Enum\StreamProtocol;

/**
 * 平台不支持所请求的能力（如某协议、某特性）。
 */
final class UnsupportedFeatureException extends LiveException
{
    public static function protocol(Platform $platform, StreamProtocol $protocol): self
    {
        return new self(\sprintf('平台 %s 不支持协议 %s。', $platform->value, $protocol->value));
    }

    public static function feature(Platform $platform, string $feature): self
    {
        return new self(\sprintf('平台 %s 不支持能力：%s。', $platform->value, $feature));
    }
}
