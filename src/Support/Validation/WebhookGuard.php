<?php

declare(strict_types=1);

namespace Kode\Live\Support\Validation;

use Kode\Live\Support\Exception\InvalidWebhookException;

/**
 * Webhook 回调的附加安全校验：重放（replay）防护。
 *
 * 签名只能证明"发送方持有密钥"，无法阻止攻击者截获一份合法的回调后反复重发
 * （例如把一次「开播」事件重放成无数次）。绝大多数平台会在回调里携带一个时间戳
 * （t / timestamp / time / ts），本守卫在「签名已通过」之后校验该时间戳是否落在
 * 可容忍的新鲜窗口内，超出即判定为重放并拒绝。
 *
 * 若 payload 中不含任何已知时间戳字段，则视为「平台未提供」，跳过校验（不报错），
 * 以避免误伤未标准化时间戳的回调来源。
 */
final class WebhookGuard
{
    /** 依优先级尝试的时间戳字段名。 */
    private const TIMESTAMP_KEYS = ['t', 'timestamp', 'time', 'ts'];

    /**
     * 校验回调时间戳新鲜度。
     *
     * @param array<string, mixed> $data 已验签的回调数据
     * @param int $now 当前 Unix 时间戳（注入时钟，便于测试）
     * @param int $maxAgeSeconds 最大允许的时间差（秒），默认 300
     *
     * @throws InvalidWebhookException 时间戳缺失外的格式异常或超出窗口
     */
    public static function assertFresh(array $data, int $now, int $maxAgeSeconds = 300): void
    {
        $raw = null;
        foreach (self::TIMESTAMP_KEYS as $key) {
            if (\array_key_exists($key, $data)) {
                $raw = $data[$key];
                break;
            }
        }

        if ($raw === null) {
            return;
        }

        if (!is_numeric($raw)) {
            throw InvalidWebhookException::malformed(\sprintf('时间戳字段 %s 非法', $key));
        }

        $ts = (int) $raw;
        if (abs($now - $ts) > $maxAgeSeconds) {
            throw InvalidWebhookException::replay($now, $ts, $maxAgeSeconds);
        }
    }
}
