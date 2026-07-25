<?php

declare(strict_types=1);

namespace Kode\Live\Transporter;

use Psr\Log\LoggerInterface;

/**
 * SRT 传输选项。
 *
 * reconnect* 控制瞬时失败（如网络抖动导致进程非零退出）下的指数退避重连；
 * 硬错误（进程无法启动）不会重试。
 */
final readonly class TransporterOptions
{
    public function __construct(
        public int $reconnectAttempts = 3,
        public int $reconnectBaseDelayMs = 200,
        public int $reconnectMaxDelayMs = 5000,
        public ?LoggerInterface $logger = null,
        public ?\Closure $sleeper = null,
    ) {
    }
}
