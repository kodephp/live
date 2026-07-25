<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * 测试用固定时钟，保证时间相关输出可断言。
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public static function at(int $timestamp): self
    {
        return new self((new DateTimeImmutable())->setTimestamp($timestamp));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
