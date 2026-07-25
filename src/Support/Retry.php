<?php

declare(strict_types=1);

namespace Kode\Live\Support;

/**
 * 指数退避重试助手。
 *
 * 用于网络类等瞬时失败可恢复的场景（如下载断点续传、云 API 调用）。
 * 默认采用「指数退避 + 全抖动」策略，避免客户端在故障恢复时同时重试造成雪崩。
 *
 * 仅对通过 $shouldRetry 判定为可重试的异常进行重试；其余异常立即上抛，
 * 以免对「校验失败」「文件损坏」等不可恢复错误做无意义重试。
 */
final class Retry
{
    /**
     * @template T
     * @param callable(): T $operation 可能抛出异常的操作
     * @param callable(\Throwable): bool $shouldRetry 判定某异常是否值得重试
     * @param callable(int $attempt, \Throwable $e): void|null $onRetry 每次重试前的钩子（如记日志）
     * @param callable(int $ms): void|null $sleeper 休眠实现，默认 usleep；测试可注入空实现
     *
     * @return T
     * @throws \Throwable 当不可重试或重试次数耗尽时，原样上抛最后一次异常
     */
    public static function backoff(
        callable $operation,
        int $maxRetries,
        int $baseDelayMs = 200,
        int $maxDelayMs = 5000,
        bool $jitter = true,
        ?callable $shouldRetry = null,
        ?callable $onRetry = null,
        ?callable $sleeper = null,
    ): mixed {
        $sleeper ??= static function (int $ms): void {
            usleep($ms * 1000);
        };

        $attempt = 0;
        while (true) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                if ($attempt >= $maxRetries || ($shouldRetry !== null && !$shouldRetry($e))) {
                    throw $e;
                }

                ++$attempt;
                if ($onRetry !== null) {
                    $onRetry($attempt, $e);
                }

                $delay = (int) min($maxDelayMs, $baseDelayMs * (2 ** ($attempt - 1)));
                if ($jitter) {
                    $delay = (int) ($delay * (0.5 + (float) random_int(0, 1000) / 1000));
                }

                $sleeper(max($delay, 1));
            }
        }
    }
}
