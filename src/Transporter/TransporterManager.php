<?php

declare(strict_types=1);

namespace Kode\Live\Transporter;

use Kode\Live\Transporter\Exception\TransportException;

/**
 * 传输层驱动管理器（与 LiveManager 同构，但作用于 Transporter 而非 LivePlatform）。
 *
 * 按 scheme 注册传输实现；SRT 场景默认注册 ExternalSrtTransporter。
 * 仅负责「选哪个 Transporter」，不关心直播平台如何拼地址、如何解析回调——
 * 因此与既有的直播 / 录制 / 回放 / 事件体系完全正交。
 */
final class TransporterManager
{
    /** @var array<string, Transporter> */
    private array $transporters = [];

    public static function default(): self
    {
        return (new self())->register(new ExternalSrtTransporter());
    }

    public function register(Transporter $transporter): self
    {
        $this->transporters[$transporter->scheme()] = $transporter;

        return $this;
    }

    public function has(string $scheme): bool
    {
        return isset($this->transporters[$scheme]);
    }

    public function transporterFor(SrtUrl $url): Transporter
    {
        return $this->transporters[$url->scheme()]
            ?? throw TransportException::unsupportedScheme($url->scheme());
    }

    public function transmit(string $source, SrtUrl $destination, ?TransporterOptions $options = null): TransmitResult
    {
        return $this->transporterFor($destination)->transmit($source, $destination, $options);
    }

    /**
     * @return list<string>
     */
    public function registered(): array
    {
        return array_keys($this->transporters);
    }
}
