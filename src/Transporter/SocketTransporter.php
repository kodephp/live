<?php

declare(strict_types=1);

namespace Kode\Live\Transporter;

use Kode\Live\Support\Retry;
use Kode\Live\Transporter\Enum\SrtMode;
use Kode\Live\Transporter\Exception\TransportException;

/**
 * 基于 PHP ext-srt 扩展的零拷贝原生 SRT 传输实现。
 *
 * 与 ExternalSrtTransporter（依赖外部 srt-live-transmit / ffmpeg 子进程）正交：本实现直接调用
 * ext-srt 的 C 绑定完成字节收发，省去子进程开销，适合高吞吐 / 低延迟的跨公网推流场景。
 *
 * 仅当运行环境加载了 ext-srt 时才可用；TransporterManager::default() 会在扩展存在时自动注册。
 * 若扩展缺失，transmit() 会抛出清晰的 TransportException（而非静默失败），便于调用方降级到外部进程实现。
 *
 * 字节来源（source）支持：
 *   - 本地文件（裸路径 / file://）与 UDP / TCP 流（udp://、tcp://）：走 PHP 流读取；
 *   - 另一个 SRT 端点（srt://）：作为 caller 连接后通过 srt_recv 读取（桥接转发）。
 * 目标（destination）统一为 SRT 端点；连接模式（caller / listener）取自 SrtUrl。
 *
 * 选项名常量采用 libsrt（SRT C API）取值，并通过可变函数调用 ext-srt，避免对扩展的编译期依赖；
 * 不同 ext-srt 绑定若存在数值差异，可用构造参数 $socketOptions 覆盖原始 optName => value 对。
 */
final class SocketTransporter implements Transporter
{
    /** libsrt SRT socket 选项名（SRT_SOCKOPT 枚举取值）。 */
    private const SRTO_SENDER = 17;
    private const SRTO_LATENCY = 19;
    private const SRTO_PASSPHRASE = 22;
    private const SRTO_PBKEYLEN = 23;

    private const AF_INET = 2;
    private const SOCK_DGRAM = 2;
    private const SRT_LEVEL = 0;
    private const CHUNK_SIZE = 1456;

    /**
     * @param array<int, mixed> $socketOptions 额外的原始 srt_setsockopt 选项（optName => value），在语义选项之后应用。
     */
    public function __construct(private readonly array $socketOptions = [])
    {
    }

    public function scheme(): string
    {
        return 'srt';
    }

    public function transmit(string $source, SrtUrl $destination, ?TransporterOptions $options = null): TransmitResult
    {
        if (!\function_exists('srt_startup')) {
            throw TransportException::extensionMissing('srt');
        }

        $options ??= new TransporterOptions();

        try {
            /** @var TransmitResult $result */
            $result = Retry::backoff(
                fn () => $this->runOnce($source, $destination),
                $options->reconnectAttempts,
                $options->reconnectBaseDelayMs,
                $options->reconnectMaxDelayMs,
                true,
                static fn (\Throwable $e): bool => $e instanceof TransportException && $e->isRetriable(),
                static function (int $attempt, \Throwable $e) use ($options): void {
                    if ($options->logger !== null) {
                        $options->logger->warning(\sprintf('SRT 原生传输重连尝试 #%d：%s', $attempt, $e->getMessage()));
                    }
                },
                $options->sleeper,
            );
        } catch (TransportException $e) {
            return new TransmitResult(
                success: false,
                error: $e->getMessage(),
            );
        }

        return $result;
    }

    /**
     * 单次传输尝试：建立连接、流式转发、返回带传输字节数的结果。
     *
     * @throws TransportException 连接 / 传输失败（瞬时故障标记为可重试）
     */
    private function runOnce(string $source, SrtUrl $destination): TransmitResult
    {
        $this->ext('srt_startup');

        $sourceHandle = $this->openSource($source);
        $destSocket = $this->openDestination($destination);

        $bytes = 0;
        try {
            while (true) {
                $chunk = $this->readSource($sourceHandle);
                if ($chunk === '' || $chunk === false) {
                    break;
                }

                $sent = $this->ext('srt_send', $destSocket, $chunk);
                if ($sent === false) {
                    throw TransportException::processFailed('srt_send', 1, 'srt_send 返回失败');
                }

                $bytes += (int) $sent;
            }
        } finally {
            $this->closeSource($sourceHandle);
            $this->ext('srt_close', $destSocket);
            $this->ext('srt_cleanup');
        }

        return new TransmitResult(
            success: true,
            bytesTransferred: $bytes,
        );
    }

    /**
     * 打开字节来源，返回 ['type' => 'stream'|'srt', 'handle' => resource|int]。
     *
     * @return array{type: string, handle: mixed}
     * @throws TransportException
     */
    private function openSource(string $source): array
    {
        if (str_starts_with($source, 'srt://')) {
            $url = SrtUrl::fromString($source);
            $sock = $this->createSocket();
            $this->applySender($sock);
            $this->ext('srt_connect', $sock, $url->host, $url->port);

            return ['type' => 'srt', 'handle' => $sock];
        }

        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            throw TransportException::spawnFailed($source, '无法以二进制读模式打开源（文件 / 流）');
        }

        return ['type' => 'stream', 'handle' => $handle];
    }

    /**
     * @param array{type: string, handle: mixed} $source
     */
    private function readSource(array $source): string|false
    {
        if ($source['type'] === 'srt') {
            /** @var string|false $data */
            $data = $this->ext('srt_recv', $source['handle'], self::CHUNK_SIZE);

            return $data;
        }

        /** @var resource $handle */
        $handle = $source['handle'];

        return fread($handle, self::CHUNK_SIZE);
    }

    /**
     * @param array{type: string, handle: mixed} $source
     */
    private function closeSource(array $source): void
    {
        if ($source['type'] === 'srt') {
            $this->ext('srt_close', $source['handle']);

            return;
        }

        /** @var resource $handle */
        $handle = $source['handle'];
        fclose($handle);
    }

    /**
     * 创建并配置目标 SRT 套接字，按模式建立连接。
     *
     * @throws TransportException
     */
    private function openDestination(SrtUrl $destination): int
    {
        $sock = $this->createSocket();
        $this->applySender($sock);

        if ($destination->passphrase !== null) {
            $this->setOpt($sock, self::SRTO_PASSPHRASE, $destination->passphrase);
        }
        if ($destination->latencyMs !== null) {
            $this->setOpt($sock, self::SRTO_LATENCY, $destination->latencyMs);
        }
        if ($destination->pbkeylen !== null) {
            $this->setOpt($sock, self::SRTO_PBKEYLEN, $destination->pbkeylen);
        }
        foreach ($this->socketOptions as $name => $value) {
            $this->setOpt($sock, $name, $value);
        }

        if ($destination->mode === SrtMode::Listener) {
            $this->ext('srt_bind', $sock, $destination->host, $destination->port);
            $this->ext('srt_listen', $sock, 1);
            /** @var int $accepted */
            $accepted = $this->ext('srt_accept', $sock);

            return $accepted;
        }

        // Caller / Rendezvous 均以主动 connect 建立（rendezvous 由对端同时发起）。
        $this->ext('srt_connect', $sock, $destination->host, $destination->port);

        return $sock;
    }

    /**
     * @throws TransportException
     */
    private function createSocket(): int
    {
        /** @var int|false $sock */
        $sock = $this->ext('srt_socket', self::AF_INET, self::SOCK_DGRAM, 0);
        if ($sock === false || $sock < 0) {
            throw TransportException::spawnFailed('srt_socket', '无法创建 SRT 套接字');
        }

        return $sock;
    }

    private function applySender(int $sock): void
    {
        $this->setOpt($sock, self::SRTO_SENDER, 1);
    }

    /**
     * 设置 SRT socket 选项；失败静默忽略（不同 ext-srt 绑定的选项名可能略有差异），不阻断连接。
     */
    private function setOpt(int $sock, int $name, mixed $value): void
    {
        $this->ext('srt_setsockopt', $sock, self::SRT_LEVEL, $name, $value);
    }

    /**
     * 通过 call_user_func_array 调用 ext-srt 函数，避免对扩展的编译期（PHPStan）依赖与
     * 「未知函数」误报；运行期若扩展缺失由调用方在 transmit() 入口拦截。
     *
     * @param array<int, mixed> $args
     */
    private function ext(string $name, ...$args): mixed
    {
        // ext-srt 为可选扩展，PHPStan 无法静态解析其函数；此处动态调用并忽略类型检查。
        /** @phpstan-ignore-next-line */
        return \call_user_func_array($name, $args);
    }
}
