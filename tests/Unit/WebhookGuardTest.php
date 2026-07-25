<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Support\Exception\InvalidWebhookException;
use Kode\Live\Support\Validation\WebhookGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookGuard::class)]
final class WebhookGuardTest extends TestCase
{
    public function testFreshTimestampAccepted(): void
    {
        WebhookGuard::assertFresh(['t' => 1000], 1000, 300);
        WebhookGuard::assertFresh(['t' => 1200], 1000, 300);
        WebhookGuard::assertFresh(['t' => 700], 1000, 300);

        self::expectNotToPerformAssertions();
    }

    public function testStaleTimestampRejected(): void
    {
        $this->expectException(InvalidWebhookException::class);

        WebhookGuard::assertFresh(['t' => 1000], 2000, 300);
    }

    public function testNonNumericTimestampRejected(): void
    {
        $this->expectException(InvalidWebhookException::class);

        WebhookGuard::assertFresh(['t' => 'abc'], 1000, 300);
    }

    public function testMissingTimestampIsNoop(): void
    {
        WebhookGuard::assertFresh([], 1000, 300);
        WebhookGuard::assertFresh(['foo' => 'bar'], 1000, 300);

        self::expectNotToPerformAssertions();
    }

    public function testOtherTimestampKeyAcceptedWithinWindow(): void
    {
        WebhookGuard::assertFresh(['timestamp' => 1000], 1100, 300);

        self::expectNotToPerformAssertions();
    }

    public function testStaleTimestampWithAltKeyRejected(): void
    {
        $this->expectException(InvalidWebhookException::class);

        WebhookGuard::assertFresh(['timestamp' => 1000], 2000, 300);
    }
}
