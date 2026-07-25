<?php

declare(strict_types=1);

namespace Kode\Live\Transporter;

/**
 * 一次 SRT 传输的结果。
 *
 * 设计上「软失败」用结果表达（success=false + error），「硬错误」（进程无法启动）才抛 TransportException，
 * 便于调用方区分「可重试的传输中断」与「需要人工介入的配置 / 环境问题」。
 */
final readonly class TransmitResult
{
    public function __construct(
        public bool $success,
        public ?int $exitCode = null,
        /** @var array<int, string>|null */
        public ?array $command = null,
        public ?int $bytesTransferred = null,
        public ?string $error = null,
    ) {
    }
}
