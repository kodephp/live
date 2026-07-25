<?php

declare(strict_types=1);

namespace Kode\Live\Support\Exception;

/**
 * Webhook 验签失败或 payload 无法解析。
 */
final class InvalidWebhookException extends LiveException
{
    public static function signatureMismatch(): self
    {
        return new self('Webhook 签名校验失败，拒绝信任该回调。');
    }

    public static function malformed(string $reason): self
    {
        return new self(\sprintf('Webhook payload 无法解析：%s', $reason));
    }
}
