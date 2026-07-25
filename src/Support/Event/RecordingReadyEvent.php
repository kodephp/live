<?php

declare(strict_types=1);

namespace Kode\Live\Support\Event;

use DateTimeImmutable;
use Kode\Live\Support\Dto\Recording;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\Platform;

/**
 * 录制文件已生成并落入对象存储的事件。
 *
 * 这是「直播 → 存储」结合的关键：云厂商录制完成后回调本事件，携带录制文件在对象存储中的位置，
 * 下游 Pipeline 据此生成回放地址或触发下载，无需轮询。
 */
final readonly class RecordingReadyEvent extends AbstractLiveEvent
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        Platform $platform,
        string $streamName,
        DateTimeImmutable $occurredAt,
        public Recording $recording,
        array $raw = [],
    ) {
        parent::__construct($platform, $streamName, $occurredAt, $raw);
    }

    public function type(): EventType
    {
        return EventType::RecordingReady;
    }
}
