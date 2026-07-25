<?php

declare(strict_types=1);

namespace Kode\Live\Support\Dto;

use DateTimeImmutable;
use Kode\Live\Support\Enum\StreamProtocol;

/**
 * 回放（点播 VOD）播放地址，通常是对象存储的带签名临时 URL。
 */
final readonly class PlaybackUrl
{
    public function __construct(
        public string $url,
        public ?DateTimeImmutable $expiresAt = null,
        public ?StreamProtocol $protocol = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
