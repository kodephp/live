<?php

declare(strict_types=1);

namespace Kode\Live\Transporter;

use Kode\Live\Support\Retry;
use Kode\Live\Transporter\Config\SrtTransporterConfig;
use Kode\Live\Transporter\Exception\TransportException;

/**
 * 基于外部进程的 SRT 传输实现（参考实现）。
 *
 * 通过 proc_open 调用系统已安装的 SRT 工具（默认 srt-live-transmit，也可配成 ffmpeg），
 * 将本地源（文件 / UDP / 另一个 SRT 地址）推送到目标 SRT 端点。
 * 传输过程中的瞬时失败（进程非零退出）会按指数退避自动重连；进程无法启动等硬错误直接抛异常。
 *
 * 该实现刻意不绑定任何 PHP SRT C 扩展，保证可移植。如需零拷贝的原生实现，
 * 另写一个实现 Transporter 接口的类（如基于 ext-srt 的 SocketTransporter），并通过
 * TransporterManager 注册即可——既有的 LivePlatform / LivePipeline / 事件体系完全不受影响。
 */
final class ExternalSrtTransporter implements Transporter
{
    public function __construct(private SrtTransporterConfig $config = new SrtTransporterConfig())
    {
    }

    public function scheme(): string
    {
        return 'srt';
    }

    /**
     * 组装将要执行的命令行 argv（用于预览 / 日志 / 测试断言）。
     *
     * @return list<string>
     */
    public function buildCommand(string $source, SrtUrl $destination): array
    {
        $command = [$this->config->binaryPath, $source, (string) $destination];

        foreach ($this->config->defaultArgs as $arg) {
            $command[] = $arg;
        }

        return $command;
    }

    public function transmit(string $source, SrtUrl $destination, ?TransporterOptions $options = null): TransmitResult
    {
        $options ??= new TransporterOptions();
        $command = $this->buildCommand($source, $destination);

        try {
            /** @var TransmitResult $result */
            $result = Retry::backoff(
                fn () => $this->runOnce($command),
                $options->reconnectAttempts,
                $options->reconnectBaseDelayMs,
                $options->reconnectMaxDelayMs,
                true,
                static fn (\Throwable $e): bool => $e instanceof TransportException && $e->isRetriable(),
                static function (int $attempt, \Throwable $e) use ($options): void {
                    if ($options->logger !== null) {
                        $options->logger->warning(\sprintf('SRT 传输重连尝试 #%d：%s', $attempt, $e->getMessage()));
                    }
                },
                $options->sleeper,
            );
        } catch (TransportException $e) {
            return new TransmitResult(
                success: false,
                exitCode: $e->exitCode(),
                command: $command,
                error: $e->getMessage(),
            );
        }

        return $result;
    }

    /**
     * @param list<string> $command
     */
    private function runOnce(array $command): TransmitResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $proc = @proc_open($this->toShellCommand($command), $descriptors, $pipes);

        if ($proc === false) {
            throw TransportException::spawnFailed($this->config->binaryPath, 'proc_open 返回 false（命令无法执行）');
        }

        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = (int) proc_close($proc);

        if ($exitCode !== 0) {
            $tail = trim($stderr) !== '' ? trim($stderr) : $stdout;
            throw TransportException::processFailed($this->toShellCommand($command), $exitCode, $tail);
        }

        return new TransmitResult(
            success: true,
            exitCode: $exitCode,
            command: $command,
        );
    }

    /**
     * @param list<string> $command
     */
    private function toShellCommand(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }
}
