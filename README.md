# kode/live

> 多平台直播综合包（PHP 8.3+）：推拉流地址签名、录制自动落对象存储、回放与断点续传下载的统一编排。

## 设计理念

- **存储不重复造轮子**：录制由云厂商侧（腾讯云 CSS / 阿里云直播）自动落对象存储（COS/OSS）。本包**不做独立存储抽象层**，存储只是驱动内的一个 `RecordingConfig`（bucket / region / 路径模板 / 格式）。
- **直播与存储的结合点是「事件」而非耦合**：直播录制完成后云厂商回调 `RecordingReadyEvent`，携带录制文件在对象存储中的位置；下游据此生成回放地址或下载归档，**无需轮询**。
- **面向接口 + 驱动模式**：新增平台 = 实现 `Contracts\LivePlatform`，不改核心。
- **精简依赖**：回放/下载所需的对象存储签名 URL 通过 `SignedUrlProvider` 委托，核心包不硬依赖任何 COS/OSS SDK。

## 架构

```
开播 ──pushUrl()──▶ 推流   云厂商自动录制落桶
                                  │
拉流 ◀──pullUrl()── 观看          ▼
                         RecordingReadyEvent (webhook 验签)
                                  │
                    ┌─────────────┴─────────────┐
              playbackFor()                  archive()
             回放签名地址                   断点续传下载到本地
```

分层（严格单向依赖）：`Contracts ← Support ← 功能模块 ← Pipeline ← LiveManager`

## 安装

```bash
composer require kode/live
```

## 支持平台

| 平台 | 标识 | 推流 | 拉流 | 录制落桶 | 回调验签 |
| --- | --- | --- | --- | --- | --- |
| 腾讯云 CSS | `tencent_css` | RTMP | FLV / HLS / WebRTC | COS | `md5(key+t)` |
| 阿里云直播 | `aliyun_live` | RTMP | FLV / HLS | OSS | 回调令牌 token |
| 通用 RTMP | `rtmp` | RTMP | FLV / HLS（可选） | 服务端 on_dvr 落桶 | 可选 `secret` 校验 |
| B站直播 | `bilibili` | RTMP | FLV / HLS | 开放平台 VOD | `md5(排序参数+secret)` |
| 抖音直播 | `douyin` | RTMP | FLV / HLS | 开放平台 VOD | `md5(排序参数+secret)` |

> 注：B站 / 抖音 真实的播放鉴权串与开播/关播由厂商开放平台 API 下发，本包驱动做「配置驱动的可复现地址拼装」+ 回调归一化，便于在服务端统一编排与测试；需要完整生命周期管理时请搭配官方 SDK。

## 快速上手

### 1. 生成推拉流地址

```php
use Kode\Live\LiveStreaming\Tencent\{TencentCssConfig, TencentCssPlatform};
use Kode\Live\Support\Dto\{RecordingConfig, StreamRequest};
use Kode\Live\Support\Enum\StreamProtocol;

$platform = new TencentCssPlatform(
    config: new TencentCssConfig(
        pushDomain: 'push.example.com',
        pullDomain: 'pull.example.com',
        pushKey: '推流鉴权KEY',
        pullKey: '拉流鉴权KEY',
        callbackKey: '回调鉴权KEY',
    ),
    recordingConfig: new RecordingConfig(bucket: 'my-bucket', region: 'ap-guangzhou'),
);

$push = $platform->pushUrl(new StreamRequest('live001'));            // rtmp://.../live001?txSecret=...
$pull = $platform->pullUrl(new StreamRequest('live001'), StreamProtocol::Flv);
```

### 2. 用管理器统一取用多平台

```php
use Kode\Live\LiveManager;
use Kode\Live\Support\Enum\Platform;

$manager = (new LiveManager())
    ->register($tencentPlatform)
    ->extend(Platform::AliyunLive, fn () => $aliyunPlatform); // 惰性构建

$manager->driver(Platform::TencentCss)->pushUrl(new StreamRequest('live001'));
```

### 3. 处理回调 → 回放 / 下载（Pipeline 编排）

```php
use Kode\Live\Pipeline\LivePipeline;
use Kode\Live\Download\ResumableDownloader;
use Kode\Live\Support\Event\RecordingReadyEvent;

$pipeline = new LivePipeline(
    platform: $platform,
    downloader: ResumableDownloader::default(),
);

$event = $pipeline->handleWebhook($rawBody, getallheaders()); // 验签并归一化
if ($event instanceof RecordingReadyEvent) {
    $playback = $pipeline->playbackFor($event, ttlSeconds: 3600); // 回放签名地址
    $result   = $pipeline->archive($event, '/data/records/live001.mp4'); // 断点续传下载
}
```

### 4. 注入对象存储签名（回放需要）

```php
use Kode\Live\Contracts\SignedUrlProvider;

final class CosSignedUrlProvider implements SignedUrlProvider
{
    public function presign(string $bucket, string $objectKey, int $ttlSeconds): string
    {
        // 内部用腾讯云 COS SDK 生成预签名 URL
    }
}
```

### 5. 通用 RTMP / B站 / 抖音

```php
use Kode\Live\LiveStreaming\Rtmp\{RtmpConfig, RtmpPlatform};
use Kode\Live\LiveStreaming\Bilibili\{BilibiliConfig, BilibiliPlatform};
use Kode\Live\Support\Dto\{RecordingConfig, StreamRequest};
use Kode\Live\Support\Enum\StreamProtocol;

// 自建 SRS / nginx-rtmp：域名 + AppName + 流名拼装，可选 ?key= 鉴权
$rtmp = new RtmpPlatform(
    new RtmpConfig(pushDomain: 'rtmp.your-srs.com', pullDomain: 'pull.your-srs.com', appName: 'live', authSecret: 'server-key'),
    new RecordingConfig(bucket: 'records', region: 'local'),
);
echo $rtmp->pushUrl(new StreamRequest('room-1'))->url;          // rtmp://rtmp.your-srs.com/live/room-1?key=server-key
echo $rtmp->pullUrl(new StreamRequest('room-1'), StreamProtocol::Flv)->url;

// B站：默认域名内置，流名即推流密钥；回调需配置 callbackSecret
$bili = new BilibiliPlatform(
    new BilibiliConfig(callbackSecret: 'your-callback-secret'),
    new RecordingConfig(bucket: 'records', region: 'local'),
);
echo $bili->pushUrl(new StreamRequest('your-stream-key'))->url;   // rtmp://live-push.bilivideo.com/live-bvc/your-stream-key
echo $bili->pullUrl(new StreamRequest('your-stream-key'), StreamProtocol::Flv)->url;
```

## 开发

```bash
composer check   # = php-cs-fixer(dry-run) + phpstan level 8 + phpunit
```

工程规则见 [`AGENTS.md`](AGENTS.md)（对所有 AI 编码助手/IDE 生效）。
新增平台驱动见 [`.workbuddy/skills/add-live-platform/SKILL.md`](.workbuddy/skills/add-live-platform/SKILL.md)。

## License

MIT
