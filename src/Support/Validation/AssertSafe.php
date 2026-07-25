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
}
