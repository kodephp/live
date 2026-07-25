<?php

declare(strict_types=1);

namespace Kode\Live\Support\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * 统一创建带安全默认值（超时、UA）的 Guzzle 客户端。
 */
final class GuzzleClientFactory
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function create(array $overrides = []): ClientInterface
    {
        $defaults = [
            'connect_timeout' => 5,
            'timeout' => 300,
            'http_errors' => true,
            'headers' => [
                'User-Agent' => 'kode-live/1.0',
            ],
        ];

        /** @var array<string, mixed> $config */
        $config = array_merge($defaults, $overrides);

        return new Client($config);
    }
}
