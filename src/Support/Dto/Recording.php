<?php

declare(strict_types=1);

namespace Kode\Live\Support\Dto;

use DateTimeImmutable;
use Kode\Live\Support\Enum\RecordingFormat;

/**
 * 一个已生成的录制文件（通常来自平台的「录制完成」回调）。
 */
final readonly class Recording
{
    public function __construct(
        public string $streamName,
        public string $appName,
        public string $bucket,
        public string $objectKey,
        public RecordingFormat $format,
        public ?string $sourceUrl = null,
        public ?int $sizeBytes = null,
        public ?int $durationSeconds = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $endedAt = null,
    ) {
    }
}
