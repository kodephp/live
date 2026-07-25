<?php

declare(strict_types=1);

namespace Kode\Live\Support\Dto;

use DateTimeImmutable;
use Kode\Live\Support\Exception\ConfigurationException;

/**
 * 生成推拉流地址的请求参数。
 */
final readonly class StreamRequest
{
    /**
     * @param string $streamName 流名（唯一标识一路直播）
     * @param string|null $appName 应用名 / AppName；为空时使用驱动默认值
     * @param DateTimeImmutable|null $expiresAt 地址过期时间；为空时由驱动按默认有效期计算
     * @param array<string, string> $params 追加到 URL 的自定义查询参数
     */
    public function __construct(
        public string $streamName,
        public ?string $appName = null,
        public ?DateTimeImmutable $expiresAt = null,
        public array $params = [],
    ) {
        if ($streamName === '') {
            throw ConfigurationException::missing('streamName');
        }
    }

    public function withExpiresAt(DateTimeImmutable $expiresAt): self
    {
        return new self($this->streamName, $this->appName, $expiresAt, $this->params);
    }
}
