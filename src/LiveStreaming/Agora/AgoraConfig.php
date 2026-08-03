<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Agora;

/**
 * 声网 Agora 直播驱动配置。
 *
 * Agora 以 RTC（实时音视频）为主，本驱动覆盖其「推流到 CDN（Publish to CDN）」场景：Agora 服务端
 * 把某路 RTC 频道转推为 RTMP 地址 `rtmp://{domain}/{app}/{stream}` 后，即可被本包统一编排。拉流
 * 默认留空——Agora 原生播放走 RTC；如需 HLS/FLV 回放，请配置由 Agora 录制 / 媒体网关产出的 CDN 域名。
 *
 * 注：Agora 频道的鉴权是 RTC Token（动态鉴权），不在本驱动范围；本驱动只负责「推流到 CDN 的
 * RTMP 地址拼装 + 回调归一化」，RTC Token 请使用官方 SDK 生成。
 */
final readonly class AgoraConfig
{
    public function __construct(
        public string $pushDomain = 'push.agora.io',
        public string $pullDomain = '',
        public string $appName = '',
        public string $callbackSecret = '',
    ) {
    }
}
