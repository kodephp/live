<?php

declare(strict_types=1);

namespace Kode\Live\Support\Enum;

/**
 * 云厂商录制文件格式。
 */
enum RecordingFormat: string
{
    case Mp4 = 'mp4';
    case Hls = 'hls';
    case Flv = 'flv';

    public function extension(): string
    {
        return match ($this) {
            self::Mp4 => '.mp4',
            self::Hls => '.m3u8',
            self::Flv => '.flv',
        };
    }
}
