<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Qiniu;

/**
 * 七牛直播云（Pili）驱动配置。
 *
 * 七牛推流为 RTMP，路径结构为 `/{hub}/{streamTitle}`，其中 hub 即流 hub（对应 AppName）；
 * 拉流支持 HLS / FLV（不同 CDN 域名）。本驱动只做「配置驱动的可复现地址拼装」，便于在自有
 * 服务里统一拼装与测试。真实的推流地址鉴权串（publishToken 等）由七牛开放平台 API 下发，
 * 接入时请用官方 SDK 取回后覆盖本配置中的域名 / hub。
 */
final readonly class QiniuConfig
{
    public function __construct(
        public string $pushDomain = 'pili-publish.qiniu.com',
        public string $pullDomain = 'pili-live-hls.qiniu.com',
        public string $hub = '',
        public string $callbackSecret = '',
    ) {
    }
}
