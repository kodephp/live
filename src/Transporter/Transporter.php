<?php

declare(strict_types=1);

namespace Kode\Live\Transporter;

/**
 * 传输层契约（与 LivePlatform 完全解耦的独立抽象）。
 *
 * 一个 Transporter 负责一种 scheme（如 srt）的字节收发：把「本地源」推送到「目标端点」。
 * 具体实现可基于外部进程（srt-live-transmit / ffmpeg），也可基于 PHP SRT 扩展（ext-srt），
 * 由 TransporterManager 按 scheme 路由。
 */
interface Transporter
{
    public function scheme(): string;

    public function transmit(string $source, SrtUrl $destination, ?TransporterOptions $options = null): TransmitResult;
}
