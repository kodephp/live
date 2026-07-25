<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\WeixinChannels;

/**
 * 微信视频号直播驱动配置。
 *
 * 视频号推流采用「RTMP 推流地址 + 流名(stream key)」，由视频号开播码提供；
 * 本驱动做配置驱动的可复现地址拼装与回调归一化。
 */
final readonly class WeixinChannelsConfig
{
    public function __construct(
        public string $pushDomain = 'livepush.weixin.qq.com',
        public string $pullDomain = '',
        public string $appName = 'live',
        public string $callbackToken = '',
    ) {
    }
}
