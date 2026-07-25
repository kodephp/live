<?php

declare(strict_types=1);

namespace Kode\Live\Transporter\Config;

/**
 * 外部 SRT 传输进程（如 srt-live-transmit / ffmpeg）的配置。
 *
 * binaryPath 为可执行文件（需在 PATH 内或绝对路径）；defaultArgs 会原样追加在
 * 「源端点」「目标端点」之后，用于注入额外参数（如 -v, -loglevel, 缓冲大小等）。
 */
final readonly class SrtTransporterConfig
{
    public function __construct(
        public string $binaryPath = 'srt-live-transmit',
        /** @var list<string> */
        public array $defaultArgs = [],
    ) {
    }
}
