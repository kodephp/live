<?php

declare(strict_types=1);

namespace Kode\Live\Contracts;

use DateTimeImmutable;
use Kode\Live\Support\Enum\EventType;
use Kode\Live\Support\Enum\Platform;

/**
 * 直播平台回调事件的统一契约。
 *
 * 各平台 webhook 经 LivePlatform::parseWebhook() 验签并归一化后，产出实现本接口的事件对象。
 */
interface LiveEvent
{
    public function type(): EventType;

    public function platform(): Platform;

    public function streamName(): string;

    public function occurredAt(): DateTimeImmutable;

    /**
     * 原始回调数据（已验签），用于需要访问平台特有字段的场景。
     *
     * @return array<string, mixed>
     */
    public function raw(): array;
}
