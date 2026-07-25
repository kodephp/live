<?php

declare(strict_types=1);

namespace Kode\Live\Support\Event;

use DateTimeImmutable;
use Kode\Live\Contracts\LiveEvent;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\Platform;

/**
 * 事件基类，收敛公共字段。
 */
abstract readonly class AbstractLiveEvent implements LiveEvent
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        protected Platform $platform,
        protected string $streamName,
        protected DateTimeImmutable $occurredAt,
        protected array $raw = [],
    ) {
    }

    public function platform(): Platform
    {
        return $this->platform;
    }

    public function streamName(): string
    {
        return $this->streamName;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function raw(): array
    {
        return $this->raw;
    }

    abstract public function type(): EventType;
}
