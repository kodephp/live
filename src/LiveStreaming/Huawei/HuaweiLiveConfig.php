<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Huawei;

use Kode\Live\Support\Exception\ConfigurationException;

/**
 * 华为云直播（CSS）驱动配置。
 *
 * 密钥字段为敏感信息，禁止被日志 / 异常 / 序列化输出。
 */
final readonly class HuaweiLiveConfig
{
    public function __construct(
        public private(set) string $pushDomain,
        public private(set) string $pullDomain,
        public private(set) string $defaultAppName = 'live',
        public private(set) string $pushKey = '',
        public private(set) string $pullKey = '',
        public private(set) string $callbackKey = '',
    ) {
        if ($pushDomain === '') {
            throw ConfigurationException::missing('huawei.pushDomain');
        }
        if ($pullDomain === '') {
            throw ConfigurationException::missing('huawei.pullDomain');
        }
        if ($defaultAppName === '') {
            throw ConfigurationException::invalid('huawei.defaultAppName', '不能为空');
        }
    }
}
