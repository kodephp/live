<?php

declare(strict_types=1);

namespace Kode\Live\Support\Validation;

use Kode\Live\Support\Exception\ConfigurationException;
use Kode\Live\Support\Exception\DownloadException;

/**
 * 输入安全断言：拦截会破坏 URL / 文件路径结构或造成注入的标识符与路径。
 *
 * 所有面向外部（推拉流地址、对象键、下载落盘路径）的用户输入都应经此校验，
 * 避免特殊字符注入到 URL / 文件路径中。各方法抛出与语义匹配的异常：
 *
 * - identifier() 校验请求级输入，失败抛 ConfigurationException；
 * - noPathTraversal() 校验下载目标路径，失败抛 DownloadException。
 */
final class AssertSafe
{
    /** 标识符最大长度，避免超长串撑爆 URL / 路径。 */
    private const IDENTIFIER_MAX = 128;

    /** 允许的流名 / 应用名字符：字母、数字、点、下划线、连字符（保守白名单）。 */
    private const IDENTIFIER_PATTERN = '/\A[A-Za-z0-9._-]+\z/';

    /**
     * 校验流名 / 应用名等标识符：非空、长度受限、仅含安全字符。
     *
     * 通过严格白名单拒绝 `/ ? # & %`、空白与控制字符，防止其被拼入
     * 推拉流 URL 后破坏结构或注入额外查询参数。
     */
    public static function identifier(string $value, string $field): void
    {
        if ($value === '') {
            throw ConfigurationException::missing($field);
        }
        if (\strlen($value) > self::IDENTIFIER_MAX) {
            throw ConfigurationException::invalid($field, \sprintf('长度超过 %d 字符上限', self::IDENTIFIER_MAX));
        }
        if (!preg_match(self::IDENTIFIER_PATTERN, $value)) {
            throw ConfigurationException::invalid(
                $field,
                '包含非法字符（仅允许字母、数字、. _ -）',
            );
        }
    }

    /**
     * 防御性校验下载目标路径：拒绝 NUL 与路径穿越(..)片段。
     */
    public static function noPathTraversal(string $path): void
    {
        if (str_contains($path, "\0")) {
            throw DownloadException::unsafePath($path);
        }

        $normalized = preg_replace('#/{2,}#', '/', str_replace('\\', '/', $path));
        if (!\is_string($normalized)) {
            throw DownloadException::unsafePath($path);
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
            throw DownloadException::unsafePath($path);
        }
    }

    /**
     * 下载源地址的 SSRF 防护（轻量级、不做 DNS 解析）。
     *
     * 仅放行 http/https，并拒绝：环回地址（127.0.0.1 / ::1）、私有网段
     * （10/8、172.16/12、192.168/16、fc00::/7）、链路本地与云元数据地址
     * （169.254.169.254 等）。以域名形式传入时不在此做反向解析——DNS 重绑定的
     * 防御应在实际发起请求时由底层 HTTP 客户端处理（超出纯校验范围）。
     *
     * @throws DownloadException 协议非法、结构残缺或命中私有/保留地址
     */
    public static function safeUrl(string $url): void
    {
        /** @var array<string, mixed>|false $parts */
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw DownloadException::unsafeUrl($url);
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw DownloadException::unsafeUrl($url);
        }

        $host = strtolower((string) $parts['host']);

        // 方括号包裹的 IPv6 字面量：剥离括号后单独判定。
        if (str_starts_with($host, '[')) {
            $ip = trim($host, '[]');
            if (!self::isPublicIp($ip)) {
                throw DownloadException::unsafeUrl($url);
            }

            return;
        }

        // IPv4 字面量或其它 IP 形式。
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false && !self::isPublicIp($host)) {
            throw DownloadException::unsafeUrl($url);
        }
        // 非 IP 字面量的域名：放行（DNS 重绑定不在此处理）。
    }

    /**
     * 判断一个 IP 是否为「可公开访问」的地址（即非环回、非私有、非保留）。
     */
    private static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // filter 标志默认不覆盖 127/8 环回，需显式拦截。
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return false;
        }

        return filter_var(
            $ip,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
