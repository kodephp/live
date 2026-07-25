<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Support\Signature\AliyunLiveSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AliyunLiveSigner::class)]
final class AliyunLiveSignerTest extends TestCase
{
    public function testAuthKeyMatchesTypeAAlgorithm(): void
    {
        $signer = new AliyunLiveSigner();
        $uri = '/live/stream001';
        $privateKey = 'secret';
        $timestamp = 1_700_000_000;

        $expectedHash = md5(\sprintf('%s-%d-%s-%s-%s', $uri, $timestamp, '0', '0', $privateKey));
        $expected = \sprintf('%d-%s-%s-%s', $timestamp, '0', '0', $expectedHash);

        self::assertSame($expected, $signer->authKey($uri, $privateKey, $timestamp));
    }

    public function testBuildUrlWithSuffixSignsFullUri(): void
    {
        $signer = new AliyunLiveSigner();
        $url = $signer->buildUrl('https', 'pull.example.com', 'live', 'stream001', '.flv', 'secret', 1_700_000_000);

        self::assertStringStartsWith('https://pull.example.com/live/stream001.flv?auth_key=', $url);
        self::assertStringContainsString($signer->authKey('/live/stream001.flv', 'secret', 1_700_000_000), $url);
    }

    public function testNoAuthWhenPrivateKeyEmpty(): void
    {
        $signer = new AliyunLiveSigner();
        $url = $signer->buildUrl('rtmp', 'push.example.com', 'live', 'stream001', '', '', 1_700_000_000);

        self::assertSame('rtmp://push.example.com/live/stream001', $url);
    }
}
