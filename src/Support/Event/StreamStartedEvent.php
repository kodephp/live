<?php

declare(strict_types=1);

namespace Kode\Live\Support\Event;

use Kode\Live\Support\Enum\EventType;

/**
 * 推流开始 / 开播事件。
 */
final readonly class StreamStartedEvent extends AbstractLiveEvent
{
    public function type(): EventType
    {
        return EventType::StreamStarted;
    }
}
