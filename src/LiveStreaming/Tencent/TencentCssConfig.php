<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Tencent;

use Kode\Live\Support\Exception\ConfigurationException;

/**
 * 腾讯云 CSS 驱动配置。
 *
 * 密钥字段为敏感信息，禁止被日志 / 异常 / 序列化输出。
 */
final readonly class TencentCssConfig
{
    public function __construct(
        public string $pushDomain,
        public string $pullDomain,
        public string $defaultAppName = 'live',
        public string $pushKey = '',
        public string $pullKey = '',
        public string $callbackKey = '',
    ) {
        if ($pushDomain === '') {
            throw ConfigurationException::missing('tencent.pushDomain');
        }
        if ($pullDomain === '') {
            throw ConfigurationException::missing('tencent.pullDomain');
        }
        if ($defaultAppName === '') {
            throw ConfigurationException::invalid('tencent.defaultAppName', '不能为空');
        }
    }
}
