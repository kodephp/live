<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Kuaishou;

/**
 * 快手直播驱动配置。
 *
 * 推拉流域名与 AppName 为占位默认值；快手真实的推流地址由快手开放平台 API
 * 下发（含各自鉴权串），接入时请用官方 SDK 取回后覆盖本配置中的域名 / AppName。
 * 本驱动仅做「配置驱动的可复现地址拼装」，便于自有服务内拼装与测试。
 */
final readonly class KuaishouConfig
{
    public function __construct(
        public string $pushDomain = 'live-push.kuaishou.com',
        public string $pullDomain = 'live-pull.kuaishou.com',
        public string $appName = 'live',
        public string $callbackSecret = '',
    ) {
    }
}
