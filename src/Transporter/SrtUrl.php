<?php

declare(strict_types=1);

namespace Kode\Live\Transporter;

use Kode\Live\Transporter\Enum\SrtMode;
use Kode\Live\Transporter\Exception\TransportException;

/**
 * SRT（Secure Reliable Transport）端点地址值对象。
 *
 * 设计为「独立传输层」的纯数据载体，与 LivePlatform 的推流地址 / Webhook 契约解耦：
 * 平台驱动只负责拼出 SRT 摄入地址（srt://host:port?...），真正的字节收发由 Transporter 完成。
 *
 * 不做 SSRF 拦截——SRT 是运营方自主管控的传输通道（常指向内网编码网关），与「下载器拉取外部 URL」
 * 的威胁模型不同，此处仅做主机名 / 端口格式校验。
 */
final readonly class SrtUrl
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $streamId = null,
        public ?string $passphrase = null,
        public ?int $latencyMs = null,
        public SrtMode $mode = SrtMode::Caller,
        public ?int $pbkeylen = null,
    ) {
        $this->assertHost($host);
        $this->assertPort($port);
    }

    public static function fromString(string $url): self
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'srt') {
            throw TransportException::malformedUrl($url);
        }

        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 0);
        if ($host === '' || $port < 1) {
            throw TransportException::malformedUrl($url);
        }

        $query = [];
        $rawQuery = $parts['query'] ?? '';
        if (\is_string($rawQuery) && $rawQuery !== '') {
            parse_str($rawQuery, $query);
        }

        $mode = isset($query['mode']) && \is_string($query['mode']) ? SrtMode::tryFrom($query['mode']) : null;

        return new self(
            host: $host,
            port: $port,
            streamId: isset($query['streamid']) && \is_string($query['streamid']) ? $query['streamid'] : null,
            passphrase: isset($query['passphrase']) && \is_string($query['passphrase']) ? $query['passphrase'] : null,
            latencyMs: isset($query['latency']) ? (int) $query['latency'] : null,
            mode: $mode ?? SrtMode::Caller,
            pbkeylen: isset($query['pbkeylen']) ? (int) $query['pbkeylen'] : null,
        );
    }

    public function scheme(): string
    {
        return 'srt';
    }

    public function __toString(): string
    {
        $query = [];
        if ($this->streamId !== null) {
            $query['streamid'] = $this->streamId;
        }
        if ($this->passphrase !== null) {
            $query['passphrase'] = $this->passphrase;
        }
        if ($this->latencyMs !== null) {
            $query['latency'] = (string) $this->latencyMs;
        }
        $query['mode'] = $this->mode->value;
        if ($this->pbkeylen !== null) {
            $query['pbkeylen'] = (string) $this->pbkeylen;
        }

        $suffix = \count($query) === 0 ? '' : '?' . http_build_query($query);

        return \sprintf('srt://%s:%d%s', $this->host, $this->port, $suffix);
    }

    private function assertHost(string $host): void
    {
        $valid = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            || filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (!$valid) {
            throw TransportException::malformedHost($host);
        }
    }

    private function assertPort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw TransportException::malformedUrl(\sprintf('srt://%s:%d', $this->host, $port));
        }
    }
}
