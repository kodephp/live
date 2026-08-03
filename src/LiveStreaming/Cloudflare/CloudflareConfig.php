<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Cloudflare;

/**
 * Cloudflare Stream 驱动配置。
 *
 * Cloudflare Stream 的「直播输入（Live Input）」会由 API 下发一个唯一的 RTMPS 推流地址
 * （`rtmps://live.cloudflare.com:443/live/{STREAM_KEY}`）与播放域名
 * （`https://customer-<code>.cloudflarestream.com`）。本驱动按约定把 `streamName` 当作 Live Input
 * 的流密钥 / 视频 ID 进行可复现拼装，便于在服务端统一编排与测试。真实的 STREAM_KEY 由 Cloudflare
 * API 下发，接入时请用官方 SDK 取回后覆盖 `pushDomain` / `pullDomain`。
 */
final readonly class CloudflareConfig
{
    public function __construct(
        public string $pushDomain = 'live.cloudflare.com:443',
        public string $pullDomain = 'customer-<code>.cloudflarestream.com',
        public string $callbackSecret = '',
    ) {
    }
}
