<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Transporter\Config\SrtTransporterConfig;
use Kode\Live\Transporter\ExternalSrtTransporter;
use Kode\Live\Transporter\SrtUrl;
use Kode\Live\Transporter\TransporterOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExternalSrtTransporter::class)]
final class ExternalSrtTransporterTest extends TestCase
{
    private function dest(): SrtUrl
    {
        return SrtUrl::fromString('srt://127.0.0.1:9000?mode=caller');
    }

    public function testBuildCommandAssemblesArgs(): void
    {
        $config = new SrtTransporterConfig(binaryPath: '/usr/bin/srt-live-transmit', defaultArgs: ['-v', '-loglevel', 'info']);
        $transporter = new ExternalSrtTransporter($config);

        $cmd = $transporter->buildCommand('udp://0.0.0.0:1234', $this->dest());

        self::assertSame(
            ['/usr/bin/srt-live-transmit', 'udp://0.0.0.0:1234', 'srt://127.0.0.1:9000?mode=caller', '-v', '-loglevel', 'info'],
            $cmd,
        );
    }

    public function testTransmitSucceedsWithTrueBinary(): void
    {
        $transporter = new ExternalSrtTransporter(new SrtTransporterConfig(binaryPath: 'true'));
        $result = $transporter->transmit('udp://0.0.0.0:1234', $this->dest());

        self::assertTrue($result->success);
        self::assertSame(0, $result->exitCode);
        self::assertNull($result->error);
    }

    public function testTransmitFailureReturnsResultNotException(): void
    {
        $transporter = new ExternalSrtTransporter(new SrtTransporterConfig(binaryPath: 'false'));
        $result = $transporter->transmit('udp://0.0.0.0:1234', $this->dest(), new TransporterOptions(reconnectAttempts: 0));

        self::assertFalse($result->success);
        self::assertNotNull($result->exitCode);
        self::assertNotNull($result->error);
    }

    public function testRetriesOnTransientFailure(): void
    {
        $retries = 0;
        $sleeper = static function () use (&$retries): void {
            ++$retries;
        };
        $transporter = new ExternalSrtTransporter(new SrtTransporterConfig(binaryPath: 'false'));
        $options = new TransporterOptions(reconnectAttempts: 2, sleeper: $sleeper);

        $result = $transporter->transmit('udp://0.0.0.0:1234', $this->dest(), $options);

        self::assertFalse($result->success);
        // maxRetries=2 -> onRetry 对第 1、2 次触发 -> sleeper 被调用两次。
        self::assertSame(2, $retries);
    }

    public function testLogsWarningOnRetry(): void
    {
        $logger = new TestLogger();
        $transporter = new ExternalSrtTransporter(new SrtTransporterConfig(binaryPath: 'false'));
        $options = new TransporterOptions(reconnectAttempts: 1, logger: $logger, sleeper: static function (): void {
        });

        $transporter->transmit('udp://0.0.0.0:1234', $this->dest(), $options);

        self::assertSame(1, $logger->warningCount());
        self::assertStringContainsString('重连尝试', $logger->lastWarning() ?? '');
    }

    public function testMissingBinaryReturnsFailureResult(): void
    {
        // 配置不存在的二进制：外层 shell 能启动，但执行「命令未找到」（exit 127）。
        // 该瞬时失败按设计以 TransmitResult(success:false) 表达，而非抛异常——
        // 异常只保留给「连外层 shell 都无法启动」的硬基础设施错误。
        $transporter = new ExternalSrtTransporter(new SrtTransporterConfig(binaryPath: '/no/such/binary-xyz'));
        $result = $transporter->transmit('udp://0.0.0.0:1234', $this->dest(), new TransporterOptions(reconnectAttempts: 0));

        self::assertFalse($result->success);
        self::assertSame(127, $result->exitCode);
        // 错误中必然包含被执行的二进制路径（macOS 报 "No such file or directory"，
        // Ubuntu dash 报 "not found"），用路径断言可跨平台稳定通过。
        self::assertStringContainsString('/no/such/binary-xyz', (string) $result->error);
    }
}
