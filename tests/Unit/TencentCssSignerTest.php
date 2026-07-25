<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Support\Signature\TencentCssSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TencentCssSigner::class)]
final class TencentCssSignerTest extends TestCase
{
    public function testSignMatchesOfficialAlgorithm(): void
    {
        $signer = new TencentCssSigner();
        $key = 'testkey';
        $stream = 'live001';
        $expires = 1_700_000_000;

        $expectedTxTime = strtoupper(dechex($expires));
        $expectedSecret = md5($key . $stream . $expectedTxTime);

        $result = $signer->sign($key, $stream, $expires);

        self::assertSame($expectedTxTime, $result['txTime']);
        self::assertSame($expectedSecret, $result['txSecret']);
    }

    public function testBuildUrlContainsAuthParams(): void
    {
        $signer = new TencentCssSigner();
        $url = $signer->buildUrl('rtmp', 'push.example.com', 'live', 'live001', 'live001', 'k', 1_700_000_000);

        self::assertStringStartsWith('rtmp://push.example.com/live/live001?', $url);
        self::assertStringContainsString('txSecret=', $url);
        self::assertStringContainsString('txTime=', $url);
    }

    public function testPullUrlSignsBaseStreamNotExtension(): void
    {
        $signer = new TencentCssSigner();
        $url = $signer->buildUrl('https', 'pull.example.com', 'live', 'live001.flv', 'live001', 'k', 1_700_000_000);
        $expected = $signer->sign('k', 'live001', 1_700_000_000);

        self::assertStringContainsString('/live/live001.flv?', $url);
        self::assertStringContainsString('txSecret=' . $expected['txSecret'], $url);
    }

    public function testNoAuthWhenKeyEmpty(): void
    {
        $signer = new TencentCssSigner();
        $url = $signer->buildUrl('rtmp', 'push.example.com', 'live', 'live001', 'live001', '', 1_700_000_000);

        self::assertSame('rtmp://push.example.com/live/live001', $url);
    }
}
