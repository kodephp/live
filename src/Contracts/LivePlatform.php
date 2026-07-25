<?php

declare(strict_types=1);

namespace Kode\Live\Contracts;

use Kode\Live\Support\Dto\PlaybackUrl;
use Kode\Live\Support\Dto\PullUrl;
use Kode\Live\Support\Dto\PushUrl;
use Kode\Live\Support\Dto\Recording;
use Kode\Live\Support\Dto\RecordingConfig;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Enum\StreamProtocol;

/**
 * 直播平台驱动契约。新增平台 = 实现本接口，不改核心。
 */
interface LivePlatform
{
    /** 平台标识。 */
    public function name(): Platform;

    /** 是否支持某推拉流协议。 */
    public function supports(StreamProtocol $protocol): bool;

    /** 生成带鉴权的推流地址。 */
    public function pushUrl(StreamRequest $request): PushUrl;

    /** 生成带鉴权的拉流 / 播放地址。 */
    public function pullUrl(StreamRequest $request, StreamProtocol $protocol): PullUrl;

    /** 当前驱动绑定的录制落存储配置。 */
    public function recordingConfig(): RecordingConfig;

    /**
     * 为某个录制文件生成回放（点播）地址。
     * 内部通过注入的 SignedUrlProvider 生成对象存储签名 URL。
     */
    public function playbackUrl(Recording $recording, int $ttlSeconds = 3600): PlaybackUrl;

    /**
     * 解析并验签平台回调（webhook），归一化为 LiveEvent。
     *
     * 实现必须先校验签名，失败抛 InvalidWebhookException，再解析 payload。
     *
     * @param array<string, string> $headers
     */
    public function parseWebhook(string $payload, array $headers = []): LiveEvent;
}
