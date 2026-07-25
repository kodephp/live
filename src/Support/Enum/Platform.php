<?php

declare(strict_types=1);

namespace Kode\Live\Support\Enum;

/**
 * 已支持的直播平台标识。
 *
 * 新增平台时在此登记一项，详见 .workbuddy/skills/add-live-platform/SKILL.md。
 */
enum Platform: string
{
    case TencentCss = 'tencent_css';
    case AliyunLive = 'aliyun_live';

    public function label(): string
    {
        return match ($this) {
            self::TencentCss => '腾讯云 CSS 直播',
            self::AliyunLive => '阿里云直播',
        };
    }
}
