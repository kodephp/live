<?php

declare(strict_types=1);

namespace Kode\Live\Support\Enum;

/**
 * 推拉流协议。
 */
enum StreamProtocol: string
{
    case Rtmp = 'rtmp';
    case Flv = 'flv';
    case Hls = 'hls';
    case Srt = 'srt';
    case WebRtc = 'webrtc';

    /**
     * 该协议默认的 URL scheme。
     */
    public function scheme(): string
    {
        return match ($this) {
            self::Rtmp => 'rtmp',
            self::Flv, self::Hls => 'https',
            self::Srt => 'srt',
            self::WebRtc => 'webrtc',
        };
    }

    /**
     * 拉流地址的文件后缀（无则为空串）。
     */
    public function extension(): string
    {
        return match ($this) {
            self::Flv => '.flv',
            self::Hls => '.m3u8',
            default => '',
        };
    }

    public function isPushable(): bool
    {
        return match ($this) {
            self::Rtmp, self::Srt, self::WebRtc => true,
            self::Flv, self::Hls => false,
        };
    }
}
