<?php

declare(strict_types=1);

namespace Kode\Live\Support\Event;

use Kode\Live\Support\Enum\EventType;

/**
 * 推流结束 / 断流事件。
 */
final readonly class StreamEndedEvent extends AbstractLiveEvent
{
    public function type(): EventType
    {
        return EventType::StreamEnded;
    }
}
