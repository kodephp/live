<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Support\Retry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Retry::class)]
final class RetryTest extends TestCase
{
    public function testReturnsImmediatelyOnSuccess(): void
    {
        $calls = 0;

        $result = Retry::backoff(
            static function () use (&$calls) {
                ++$calls;

                return 'ok';
            },
            3,
            sleeper: static function (): void {
            },
        );

        self::assertSame('ok', $result);
        self::assertSame(1, $calls);
    }

    public function testRetriesThenSucceeds(): void
    {
        $calls = 0;

        $result = Retry::backoff(
            static function () use (&$calls) {
                ++$calls;
                if ($calls < 3) {
                    throw new \RuntimeException('transient');
                }

                return 'done';
            },
            3,
            sleeper: static function (): void {
            },
        );

        self::assertSame('done', $result);
        self::assertSame(3, $calls);
    }

    public function testRethrowsAfterExhaustingRetries(): void
    {
        $calls = 0;
        $thrown = null;

        try {
            Retry::backoff(
                static function () use (&$calls) {
                    ++$calls;
                    throw new \RuntimeException('boom');
                },
                2,
                sleeper: static function (): void {
                },
            );
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertSame(3, $calls); // 初次 + 2 次重试
    }

    public function testShouldRetryFalseRethrowsImmediately(): void
    {
        $calls = 0;

        $this->expectException(\InvalidArgumentException::class);

        Retry::backoff(
            static function () use (&$calls) {
                ++$calls;
                throw new \InvalidArgumentException('nope');
            },
            3,
            shouldRetry: static fn (\Throwable $e): bool => $e instanceof \RuntimeException,
            sleeper: static function (): void {
            },
        );
    }
}
