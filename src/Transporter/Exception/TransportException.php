<?php

declare(strict_types=1);

namespace Kode\Live\Transporter\Exception;

use Kode\Live\Support\Exception\LiveException;

/**
 * 传输层异常。继承自 LiveException，因此捕获 LiveException 即可覆盖传输错误。
 *
 * 约定：message 中禁止出现密钥 / 鉴权串等敏感信息；passphrase 等绝不回显。
 */
final class TransportException extends LiveException
{
    private function __construct(string $message, private ?int $exitCode = null, private bool $retriable = false)
    {
        parent::__construct($message);
    }

    /**
     * 进程无法启动（二进制不存在 / 无执行权限 / proc_open 失败）——属于硬错误，不应重试。
     */
    public static function spawnFailed(string $binary, string $reason): self
    {
        return new self(\sprintf('无法启动 SRT 传输进程（%s）：%s', $binary, $reason), null, false);
    }

    /**
     * 进程已启动但异常退出——通常属于瞬时网络故障，可重试。
     */
    public static function processFailed(string $command, int $exitCode, string $stderr): self
    {
        $tail = $stderr === '' ? '（无 stderr 输出）' : $stderr;

        return new self(\sprintf('SRT 传输进程异常退出（code=%d）：%s', $exitCode, $tail), $exitCode, true);
    }

    public static function malformedUrl(string $url): self
    {
        return new self(\sprintf('非法 SRT 地址：%s', $url), null, false);
    }

    public static function malformedHost(string $host): self
    {
        return new self(\sprintf('非法 SRT 主机名：%s', $host), null, false);
    }

    public static function unsupportedScheme(string $scheme): self
    {
        return new self(\sprintf('不支持的传输协议：%s', $scheme), null, false);
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function isRetriable(): bool
    {
        return $this->retriable;
    }
}
