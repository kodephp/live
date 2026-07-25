<?php

declare(strict_types=1);

namespace Kode\Live\Support\Dto;

use DateTimeImmutable;
use Kode\Live\Support\Enum\StreamProtocol;

/**
 * 推流地址（含鉴权串，视为敏感信息，勿打印到公共输出）。
 */
final readonly class PushUrl
{
    public function __construct(
        public string $url,
        public StreamProtocol $protocol,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
