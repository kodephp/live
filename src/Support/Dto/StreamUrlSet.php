<?php

declare(strict_types=1);

namespace Kode\Live\Support\Dto;

use Kode\Live\Support\Enum\StreamProtocol;

/**
 * 一路直播的「地址集合」：推流地址 + 多种协议的拉流地址 + 可选回放地址。
 *
 * 通过 LiveManager::urlBundle() 一次性取得，省去分别调用 pushUrl / pullUrl / playbackUrl。
 */
final readonly class StreamUrlSet
{
    /**
     * @param list<PullUrl> $pull 不同协议的拉流地址
     */
    public function __construct(
        public PushUrl $push,
        public array $pull,
        public ?PlaybackUrl $playback = null,
    ) {
    }

    /**
     * 按协议取出对应的拉流地址（不支持或未生成则返回 null）。
     */
    public function pull(StreamProtocol $protocol): ?PullUrl
    {
        foreach ($this->pull as $url) {
            if ($url->protocol === $protocol) {
                return $url;
            }
        }

        return null;
    }
}
