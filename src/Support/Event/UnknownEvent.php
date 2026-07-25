<?php

declare(strict_types=1);

namespace Kode\Live\Support\Event;

use Kode\Live\Support\Enum\EventType;

/**
 * 无法归一化的事件（保留原始数据供上层排查）。
 */
final readonly class UnknownEvent extends AbstractLiveEvent
{
    public function type(): EventType
    {
        return EventType::Unknown;
    }
}
