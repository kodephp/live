<?php

declare(strict_types=1);

namespace Kode\Live\LiveStreaming;

use Kode\Live\Support\Dto\PullUrl;
use Kode\Live\Support\Dto\PushUrl;
use Kode\Live\Support\Dto\StreamRequest;
use Kode\Live\Support\Enum\StreamProtocol;
use Kode\Live\Support\Exception\UnsupportedFeatureException;

/**
 * 基于「RTMP 推流地址 + 流名(stream key)」的直播平台驱动基类。
 *
 * 适用：自建 RTMP（SRS / nginx-rtmp）、B站、抖音 等以 RTMP 推流 + 流名标识直播的平台。
 * 推拉流地址由配置驱动（域名 / AppName / 鉴权 query），不依赖厂商私有签名算法；
 * 子类只需补：平台默认域名、webhook 验签、事件映射。
 *
 * 注意：此类平台（尤其 B站 / 抖音）真实的播放鉴权串由厂商开放平台 API 下发，
 * 本基类仅做「配置驱动的可复现地址生成」，便于在自有服务里拼装与测试。
 */
abstract class AbstractStreamKeyPlatform extends AbstractLivePlatform
{
    public function supports(StreamProtocol $protocol): bool
    {
        return match ($protocol) {
            StreamProtocol::Rtmp => true,
            StreamProtocol::Flv, StreamProtocol::Hls => $this->pullDomain() !== '',
            default => false,
        };
    }

    public function pushUrl(StreamRequest $request): PushUrl
    {
        $app = $request->appName ?? $this->defaultAppName();
        $path = $app !== '' ? "/{$app}/{$request->streamName}" : "/{$request->streamName}";
        $base = "rtmp://{$this->pushDomain()}{$path}";

        return new PushUrl($this->appendAuth($base, $request->params), StreamProtocol::Rtmp);
    }

    public function pullUrl(StreamRequest $request, StreamProtocol $protocol): PullUrl
    {
        $this->assertSupported($protocol);

        $domain = $this->pullDomain();
        if ($domain === '') {
            throw UnsupportedFeatureException::feature($this->name(), 'HTTP 拉流（未配置 pullDomain）');
        }

        $app = $request->appName ?? $this->defaultAppName();
        $path = $app !== '' ? "/{$app}/{$request->streamName}" : "/{$request->streamName}";
        $base = \sprintf('%s://%s%s%s', $protocol->scheme(), $domain, $path, $protocol->extension());

        return new PullUrl($this->appendAuth($base, $request->params), $protocol);
    }

    /** 平台默认推流域名（子类由配置提供）。 */
    abstract protected function defaultPushDomain(): string;

    /** 平台默认拉流域名；为空表示不支持 HTTP 拉流。 */
    protected function defaultPullDomain(): string
    {
        return '';
    }

    /** 平台默认 AppName；可为空。 */
    protected function defaultAppName(): string
    {
        return '';
    }

    /** Webhook 验签密钥；为空表示关闭验签（仅自建可信内网场景）。 */
    abstract protected function webhookSecret(): string;

    /**
     * 追加到推拉流地址的固定鉴权参数（如 RTMP 服务器的 ?key=）。
     *
     * @return array<string, string>
     */
    protected function authParams(): array
    {
        return [];
    }

    protected function pushDomain(): string
    {
        return $this->defaultPushDomain();
    }

    protected function pullDomain(): string
    {
        return $this->defaultPullDomain();
    }

    /**
     * @param array<string, string> $params
     */
    protected function appendAuth(string $base, array $params): string
    {
        $all = [...$this->authParams(), ...$params];
        if ($all === []) {
            return $base;
        }

        return $base . '?' . http_build_query($all);
    }
}
