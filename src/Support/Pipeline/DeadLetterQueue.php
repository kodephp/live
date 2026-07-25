<?php

declare(strict_types=1);

namespace Kode\Live\Support\Pipeline;

use DateTimeImmutable;
use Kode\Live\Contracts\LiveEvent;

/**
 * 死信队列（Dead-Letter Queue）。
 *
 * 流水线里任何一步失败（监听器抛异常、自动归档下载失败等）都不应悄悄丢失——
 * 而是把「事件 + 异常 + 时间戳」原样落入死信队列，便于事后排查、补跑或告警。
 *
 * 设计为内存态、可注入：默认每条流水线自带一个；需要跨进程/持久化时，
 * 调用方可用自定义实现替换（实现同样的 push / all / drain 语义）。
 *
 * @phpstan-type DeadLetter array{event: LiveEvent, error: \Throwable, at: DateTimeImmutable}
 */
final class DeadLetterQueue
{
    /** @var list<DeadLetter> */
    private array $items = [];

    public function push(LiveEvent $event, \Throwable $error, DateTimeImmutable $at): void
    {
        $this->items[] = ['event' => $event, 'error' => $error, 'at' => $at];
    }

    /**
     * 当前所有死信（不清除）。
     *
     * @return list<DeadLetter>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    /**
     * 取出并清空队列（典型用于定时补跑 / 上报后重置）。
     *
     * @return list<DeadLetter>
     */
    public function drain(): array
    {
        $items = $this->items;
        $this->items = [];

        return $items;
    }
}
