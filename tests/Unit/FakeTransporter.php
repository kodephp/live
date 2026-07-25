<?php

declare(strict_types=1);

namespace Kode\Live\Tests\Unit;

use Kode\Live\Transporter\SrtUrl;
use Kode\Live\Transporter\TransmitResult;
use Kode\Live\Transporter\Transporter;
use Kode\Live\Transporter\TransporterOptions;

/**
 * 测试用 Transporter 桩：记录调用、按构造参数返回成功 / 失败。
 */
final class FakeTransporter implements Transporter
{
    /** @var list<array{source: string, destination: string}> */
    public array $calls = [];

    public function __construct(private string $schemeName = 'srt', private bool $result = true)
    {
    }

    public function scheme(): string
    {
        return $this->schemeName;
    }

    public function transmit(string $source, SrtUrl $destination, ?TransporterOptions $options = null): TransmitResult
    {
        $this->calls[] = ['source' => $source, 'destination' => (string) $destination];

        return new TransmitResult(success: $this->result);
    }
}
