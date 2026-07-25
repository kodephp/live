<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * 测试用轻量日志实现：记录所有条目，便于断言重试 / 告警是否触发。
 */
final class TestLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log(mixed $level, mixed $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function warningCount(): int
    {
        $n = 0;
        foreach ($this->records as $record) {
            if ($record['level'] === LogLevel::WARNING) {
                ++$n;
            }
        }

        return $n;
    }

    public function lastWarning(): ?string
    {
        foreach (array_reverse($this->records) as $record) {
            if ($record['level'] === LogLevel::WARNING) {
                return $record['message'];
            }
        }

        return null;
    }
}
