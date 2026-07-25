<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Douyin;

/**
 * 抖音直播驱动配置。
 *
 * 默认域名与 AppName 为抖音 CDN 推拉流域名；真实推流地址（含流名）与带签名播放地址
 * 由抖音开放平台 API 下发，本驱动仅做可复现的地址拼装与回调归一化。
 */
final readonly class DouyinConfig
{
    public function __construct(
        public string $pushDomain = 'push-rtmp.douyincdn.com',
        public string $pullDomain = 'pull-flv.douyincdn.com',
        public string $appName = 'thirdparty',
        public string $callbackSecret = '',
    ) {
    }
}
