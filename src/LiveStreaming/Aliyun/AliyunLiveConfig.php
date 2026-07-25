<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming\Aliyun;

use Kode\Live\Support\Exception\ConfigurationException;

/**
 * 阿里云直播驱动配置。
 *
 * pushKey / pullKey 为 URL 鉴权（A 方式）的主 KEY；callbackToken 用于校验回调来源。
 * 均为敏感信息，禁止入日志 / 异常 / 序列化输出。
 */
final readonly class AliyunLiveConfig
{
    public function __construct(
        public string $pushDomain,
        public string $pullDomain,
        public string $defaultAppName = 'live',
        public string $pushKey = '',
        public string $pullKey = '',
        public string $callbackToken = '',
    ) {
        if ($pushDomain === '') {
            throw ConfigurationException::missing('aliyun.pushDomain');
        }
        if ($pullDomain === '') {
            throw ConfigurationException::missing('aliyun.pullDomain');
        }
        if ($defaultAppName === '') {
            throw ConfigurationException::invalid('aliyun.defaultAppName', '不能为空');
        }
    }
}
