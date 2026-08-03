<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Upyun;

/**
 * 又拍云直播驱动配置。
 *
 * 又拍云推流为 RTMP，真实推流域名为 `{服务名}.uplive-upaiyun.com`，本配置默认给出裸域名，
 * 接入时请把 `pushDomain` 写成 `你的服务名.uplive-upaiyun.com`；拉流 HLS / FLV 同理。本驱动
 * 只做「配置驱动的可复现地址拼装」，便于在自有服务里统一拼装与测试。真实的推流鉴权串由又拍云
 * 控制台 / 开放 API 下发，接入时按需覆盖默认域名与 AppName。
 */
final readonly class UpyunConfig
{
    public function __construct(
        public string $pushDomain = 'uplive-upaiyun.com',
        public string $pullDomain = 'ulive-upaiyun.com',
        public string $appName = '',
        public string $callbackSecret = '',
    ) {
    }
}
