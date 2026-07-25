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
abstract readonly class AbstractLiveEvent implements LiveEvent, \JsonSerializable
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

    /**
     * 结构化输出，便于落库 / 投递消息队列 / 日志。
     *
     * @return array{platform: string, streamName: string, occurredAt: string, type: string, raw: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform->value,
            'streamName' => $this->streamName,
            'occurredAt' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'type' => $this->type()->value,
            'raw' => $this->raw,
        ];
    }

    /**
     * @return array{platform: string, streamName: string, occurredAt: string, type: string, raw: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    abstract public function type(): EventType;
}
