<?php

declare(strict_types=1);

namespace Kode\Live\Support\Signature;

/**
 * 腾讯云 CSS（云直播）推拉流地址鉴权算法。
 *
 * 官方算法（推流防盗链 / 拉流防盗链同构）：
 *   txTime   = 大写十六进制的过期 Unix 时间戳
 *   txSecret = md5(key + streamName + txTime)
 *   最终地址追加 ?txSecret={txSecret}&txTime={txTime}
 *
 * 本类为纯函数实现，不含任何 IO，便于单测（固定时间戳可断言输出）。
 *
 * @see https://cloud.tencent.com/document/product/267/32735
 */
final class TencentCssSigner
{
    /**
     * 计算鉴权参数。
     *
     * @return array{txSecret: string, txTime: string}
     */
    public function sign(string $key, string $streamName, int $expiresAt): array
    {
        $txTime = strtoupper(dechex($expiresAt));
        $txSecret = md5($key . $streamName . $txTime);

        return ['txSecret' => $txSecret, 'txTime' => $txTime];
    }

    /**
     * 拼装带鉴权串的完整地址。
     *
     * 注意：txSecret 始终基于「不含扩展名的流名」计算，而 URL 路径可携带 .flv / .m3u8 等后缀，
     * 因此路径流名与签名流名分离。推流场景两者相同。
     *
     * @param string $pathStreamName 出现在 URL 路径中的流名（拉流时可含 .flv/.m3u8）
     * @param string $signStreamName 参与签名的流名（不含扩展名）
     * @param array<string, string> $extraParams 追加的自定义查询参数
     */
    public function buildUrl(
        string $scheme,
        string $domain,
        string $appName,
        string $pathStreamName,
        string $signStreamName,
        string $key,
        int $expiresAt,
        array $extraParams = [],
    ): string {
        $base = \sprintf('%s://%s/%s/%s', $scheme, $domain, $appName, $pathStreamName);

        $query = $extraParams;
        if ($key !== '') {
            $auth = $this->sign($key, $signStreamName, $expiresAt);
            $query['txSecret'] = $auth['txSecret'];
            $query['txTime'] = $auth['txTime'];
        }

        if ($query === []) {
            return $base;
        }

        return $base . '?' . http_build_query($query);
    }
}
