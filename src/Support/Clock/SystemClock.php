<?php

declare(strict_types=1);

namespace Kode\Live\Support\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * 默认时钟实现，返回系统当前时间（UTC）。
 *
 * 业务代码一律通过注入的 ClockInterface 获取时间，便于测试中替换为固定时钟。
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
