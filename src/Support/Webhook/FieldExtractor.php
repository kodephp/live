<?php

declare(strict_types=1);

namespace Kode\Live\Support\Webhook;

use Kode\Live\Support\Exception\InvalidWebhookException;

/**
 * Webhook 回调字段抽取与签名校验的共享工具。
 *
 * 各平台驱动的 parseWebhook() 大量重复了「取字符串字段 / 取整数字段 / 取可空整数字段 /
 * 校验 md5(排序参数 + secret) 签名」等样板逻辑。本类把它们收敛为纯静态方法，
 * 新平台直接复用，避免在每个驱动里粘贴同一套私有辅助方法。
 *
 * 所有方法只做「取值 + 结构校验」，不涉及任何网络 IO。
 */
final class FieldExtractor
{
    /**
     * 从回调数据取字符串字段（标量转字符串，缺失或非法回退默认值）。
     *
     * @param array<string, mixed> $data
     */
    public static function stringField(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return \is_scalar($value) ? (string) $value : $default;
    }

    /**
     * 从回调数据取整数字段（非数字回退默认值）。
     *
     * @param array<string, mixed> $data
     */
    public static function intField(array $data, string $key, int $default = -1): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * 从回调数据取可空整数字段（缺失或非数字返回 null）。
     *
     * @param array<string, mixed> $data
     */
    public static function nullableIntField(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * 校验「md5(排序参数 + secret)」风格签名（B站 / 抖音 / 视频号 等通用方案）。
     *
     * 校验顺序：
     *   1) 取出 sign 字段，缺失即拒绝；
     *   2) 从待签集合中剔除 sign，剩余参数按 key 升序排列后拼成 `k=v&` 序列；
     *   3) 追加 `secret=...` 并以 md5 计算，与 sign 做时序安全比对。
     *
     * @param array<string, mixed> $data 已 JSON 解析的回调数据
     * @param string $secret 验签密钥（非空；为空先由调用方抛 ConfigurationException）
     * @param string $signKey 携带签名的字段名，默认 `sign`
     *
     * @throws InvalidWebhookException 签名缺失或比对失败
     */
    public static function verifySortedMd5(array $data, string $secret, string $signKey = 'sign'): void
    {
        $sign = self::stringField($data, $signKey);
        if ($sign === '') {
            throw InvalidWebhookException::malformed(\sprintf('缺少 %s 字段', $signKey));
        }

        $params = $data;
        unset($params[$signKey]);
        ksort($params);

        $raw = '';
        foreach ($params as $key => $value) {
            $raw .= $key . '=' . (string) $value . '&';
        }
        $raw .= 'secret=' . $secret;

        if (!hash_equals(md5($raw), $sign)) {
            throw InvalidWebhookException::signatureMismatch();
        }
    }
}
