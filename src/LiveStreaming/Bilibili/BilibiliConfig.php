<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Bilibili;

/**
 * B站（哔哩哔哩）直播驱动配置。
 *
 * 默认域名与 AppName 为 B站公开推拉流域名；真实播放鉴权串由 B站开放平台 API 下发，
 * 本驱动仅做可复现的地址拼装。
 */
final readonly class BilibiliConfig
{
    public function __construct(
        public string $pushDomain = 'live-push.bilivideo.com',
        public string $pullDomain = 'live-pull.biliapi.com',
        public string $appName = 'live-bvc',
        public string $callbackSecret = '',
    ) {
    }
}
