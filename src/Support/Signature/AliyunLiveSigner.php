<?php

declare(strict_types=1);

namespace Kode\Live\Support\Signature;

/**
 * 阿里云直播 URL 鉴权（A 方式）。
 *
 * 官方算法：
 *   uri       = /{app}/{stream}                （拉流 flv 时 stream 需带 .flv 后缀）
 *   sstring   = "{uri}-{timestamp}-{rand}-{uid}-{privateKey}"
 *   hashvalue = md5(sstring)
 *   auth_key  = "{timestamp}-{rand}-{uid}-{hashvalue}"
 *   最终地址追加 ?auth_key={auth_key}
 *
 * 纯函数实现，无 IO，便于单测。
 *
 * @see https://help.aliyun.com/document_detail/199349.html
 */
final class AliyunLiveSigner
{
    /**
     * 计算 auth_key。
     */
    public function authKey(
        string $uri,
        string $privateKey,
        int $timestamp,
        string $rand = '0',
        string $uid = '0',
    ): string {
        $sstring = \sprintf('%s-%d-%s-%s-%s', $uri, $timestamp, $rand, $uid, $privateKey);
        $hash = md5($sstring);

        return \sprintf('%d-%s-%s-%s', $timestamp, $rand, $uid, $hash);
    }

    /**
     * 拼装带鉴权串的完整地址。
     *
     * @param array<string, string> $extraParams 追加的自定义查询参数
     */
    public function buildUrl(
        string $scheme,
        string $domain,
        string $appName,
        string $streamName,
        string $suffix,
        string $privateKey,
        int $expiresAt,
        array $extraParams = [],
    ): string {
        $uri = \sprintf('/%s/%s%s', $appName, $streamName, $suffix);
        $base = \sprintf('%s://%s%s', $scheme, $domain, $uri);

        $query = $extraParams;
        if ($privateKey !== '') {
            $query['auth_key'] = $this->authKey($uri, $privateKey, $expiresAt);
        }

        if ($query === []) {
            return $base;
        }

        return $base . '?' . http_build_query($query);
    }
}
