<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamRequest::class)]
final class StreamRequestTest extends TestCase
{
    public function testValidRequestIsAccepted(): void
    {
        $request = new StreamRequest('live-001', appName: 'app_x');

        self::assertSame('live-001', $request->streamName);
        self::assertSame('app_x', $request->appName);
    }

    public function testNullAppNameIsAllowed(): void
    {
        $request = new StreamRequest('stream1');

        self::assertNull($request->appName);
    }

    public function testEmptyStreamNameIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new StreamRequest('');
    }

    public function testStreamNameWithIllegalCharactersIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        // 斜杠会破坏 URL 路径结构
        new StreamRequest('room/1');
    }

    public function testStreamNameWithWhitespaceIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new StreamRequest('live 001');
    }

    public function testStreamNameWithQueryInjectionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        // 问号/& 会注入额外查询参数
        new StreamRequest('live?x=1&y=2');
    }

    public function testAppNameWithIllegalCharactersIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new StreamRequest('stream1', appName: 'bad/app');
    }
}
