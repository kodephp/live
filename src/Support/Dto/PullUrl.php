<?php

declare(strict_types=1);

namespace Kode\Live\Support\Dto;

use DateTimeImmutable;
use Kode\Live\Support\Enum\StreamProtocol;

/**
 * 拉流 / 播放地址（若开启防盗链则含鉴权串）。
 */
final readonly class PullUrl
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
