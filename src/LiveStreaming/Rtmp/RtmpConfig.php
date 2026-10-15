<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Rtmp;

use Kode\Live\Support\Exception\ConfigurationException;

/**
 * 通用 RTMP（自建 SRS / nginx-rtmp 等）驱动配置。
 */
final readonly class RtmpConfig
{
    public function __construct(
        public private(set) string $pushDomain,
        public private(set) string $pullDomain = '',
        public private(set) string $appName = '',
        public string $authParam = 'key',
        public private(set) string $authSecret = '',
        public private(set) string $callbackSecret = '',
    ) {
        if ($pushDomain === '') {
            throw ConfigurationException::missing('rtmp.pushDomain');
        }
    }
}
