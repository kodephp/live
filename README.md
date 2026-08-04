# kode/live

> 多平台直播综合包（PHP 8.3+）：推拉流地址签名、录制自动落对象存储、回放与断点续传下载的统一编排。

## 设计理念

- **存储不重复造轮子**：录制由云厂商侧（腾讯云 CSS / 阿里云直播）自动落对象存储（COS/OSS）。本包**不做独立存储抽象层**，存储只是驱动内的一个 `RecordingConfig`（bucket / region / 路径模板 / 格式）。
- **直播与存储的结合点是「事件」而非耦合**：直播录制完成后云厂商回调 `RecordingReadyEvent`，携带录制文件在对象存储中的位置；下游据此生成回放地址或下载归档，**无需轮询**。
- **面向接口 + 驱动模式**：新增平台 = 实现 `Contracts\LivePlatform`，不改核心。
- **精简依赖**：回放/下载所需的对象存储签名 URL 通过 `SignedUrlProvider` 委托，核心包不硬依赖任何 COS/OSS SDK。
- **传输层独立**：SRT 等传输协议建模为独立的 `Transporter` 抽象（见 §6），与平台驱动正交，新增传输实现不触碰直播核心。

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
| 华为云直播（CSS） | `huawei` | RTMP | FLV / HLS | OBS | `md5(callbackKey+t)` |
| 微信视频号 | `weixin_channels` | RTMP | 可选（自有 CDN） | 开放平台 | `md5(排序参数+token)` |
| 快手直播 | `kuaishou` | RTMP | FLV / HLS | 开放平台 VOD | `md5(排序参数+secret)` |
| 七牛直播云（Pili） | `qiniu` | RTMP | HLS / FLV | 开放平台 VOD | `md5(排序参数+secret)` |
| 又拍云直播 | `upyun` | RTMP | HLS / FLV | 开放平台 | `md5(排序参数+secret)` |
| Cloudflare Stream | `cloudflare` | RTMPS | HLS | Stream 存储 | `md5(排序参数+secret)` |
| 声网 Agora（推流到 CDN） | `agora` | RTMP（到 CDN） | 可选（CDN 域名） | 录制 / 媒体网关 | `md5(排序参数+secret)` |

> 注：B站 / 抖音 / 快手 真实的播放鉴权串与开播/关播由厂商开放平台 API 下发，本包驱动做「配置驱动的可复现地址拼装」+ 回调归一化，便于在服务端统一编排与测试；需要完整生命周期管理时请搭配官方 SDK。

> 关于 **SRT（Secure Reliable Transport）接入**：SRT 是传输层协议，与 `LivePlatform` 的「推流地址拼装 + Webhook 回调」契约不在同一抽象层。本包把它独立建模为 `Transporter` 抽象（见 §6 传输层），由 `TransporterManager` 按 scheme 路由，与直播 / 录制 / 回放 / 事件体系完全正交，不破坏既有分层。

## 安全与健壮性

本包把安全作为一等公民，关键能力开箱即用：

- **Webhook 重放防护**：各平台回调验签通过后，额外校验回调时间戳（`t` / `timestamp` 等）是否落在新鲜窗口内（默认 300s，可通过构造参数 `webhookMaxAgeSeconds` 调整），超出即判定为重放攻击并拒绝。
- **下载器 SSRF 防护**：`ResumableDownloader` 在发起请求前调用 `AssertSafe::safeUrl()`，仅放行 `http/https`，并拒绝环回（127.0.0.1 / ::1）、私有网段（10/8、172.16/12、192.168/16、fc00::/7）与云元数据地址（169.254.169.254）。
- **下载完整性**：支持 `expectedSize` / `expectedSha256`，落盘后用 `hash_equals` 时序安全比对，防止文件损坏或被篡改。
- **输入安全**：推拉流地址使用的流名 / 应用名经 `AssertSafe::identifier()` 白名单校验（仅 `[A-Za-z0-9._-]`），下载落盘路径经穿越防护，杜绝 URL 注入与路径穿越。
- **时序安全验签**：所有 webhook 签名比对均使用 `hash_equals()`，避免时序侧信道。
- **指数退避重试**：网络类瞬时失败通过 `Support\Retry` 进行指数退避 + 抖动重试，仅对可恢复异常（如下载的 `GuzzleException`）重试，校验失败不重试。

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

### 2b. 一次性取齐推流 / 拉流 / 回放地址

```php
use Kode\Live\Support\Dto\Recording;

// 推流地址 + FLV/HLS 拉流地址一次性拿到；传入 Recording 还可附带回放地址
$set = $manager->urlBundle(
    platform: Platform::Bilibili,
    request: new StreamRequest('your-stream-key'),
    pullProtocols: [StreamProtocol::Flv, StreamProtocol::Hls],
    recording: $recording, // 可选
);

echo $set->push->url;                  // 推流地址
echo $set->pull(StreamProtocol::Hls)?->url; // 指定协议拉流地址
echo $set->playback?->url;            // 回放签名地址（若提供 recording）
```

### 2c. 事件序列化（落库 / 消息队列 / 日志）

所有事件均实现 `JsonSerializable`，可直接 `json_encode` 或调用 `toArray()`：

```php
$event = $platform->parseWebhook($rawBody);
$payload = $event->toArray();         // ['platform'=>..., 'streamName'=>..., 'occurredAt'=>..., 'type'=>..., 'raw'=>...]
file_put_contents('events.log', json_encode($event) . "\n");
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

#### 3b. 注册本地监听器（按优先级、互不阻塞）

除可选的 PSR-14 事件总线外，`LivePipeline` 支持用 `on()` 注册本地监听器。事件归一化后按
`priority` 降序触发；**单个监听器抛错不会中断流水线**（错误被记录后跳过），编排更健壮。

```php
use Kode\Live\Support\Event\RecordingReadyEvent;
use Kode\Live\Support\Event\StreamStartedEvent;

$pipeline
    ->on(StreamStartedEvent::class, fn ($e) => notifyStart($e->streamName()), priority: 100)
    ->on(RecordingReadyEvent::class, fn ($e) => $pipeline->archive($e, '/data/'.$e->streamName().'.mp4'))
    ->handleWebhook($rawBody, getallheaders());
```

#### 3c. 自动归档与死信队列

除了手动 `archive()`，还可以用 `autoArchive()` 注册一个归档策略；此后每次收到
`RecordingReadyEvent` 且注入了 `ResumableDownloader` 时，流水线会**自动**把录制文件下载到
策略生成的本地路径。

归档策略只决定「落盘到哪」：内置 `TemplateArchiveStrategy` 用占位符拼路径
（`{date}` 当前日期 Y/m/d、`{streamName}` 流名、`{objectKey}` 桶内完整 key、
`{baseName}` 文件名、`{bucket}` 存储桶）。

```php
use Kode\Live\Support\Archive\TemplateArchiveStrategy;

$pipeline->autoArchive(new TemplateArchiveStrategy('/data/records/{date}/{streamName}/{baseName}'));
$pipeline->handleWebhook($rawBody, getallheaders()); // 录制完成即自动下载
```

**失败不丢事件**：无论本地监听器抛错，还是自动归档下载失败，都不会中断流水线，
而是原样落入**死信队列**（事件 + 异常 + 时间戳），便于事后排查或补跑：

```php
if ($pipeline->deadLetters()->count() > 0) {
    foreach ($pipeline->drainDeadLetters() as $item) {
        report($item['event'], $item['error'], $item['at']); // 告警 / 补偿重试
    }
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

### 5b. 华为云直播（CSS）/ 微信视频号

```php
use Kode\Live\LiveStreaming\Huawei\{HuaweiLiveConfig, HuaweiLivePlatform};
use Kode\Live\LiveStreaming\WeixinChannels\{WeixinChannelsConfig, WeixinChannelsPlatform};
use Kode\Live\Support\Dto\{RecordingConfig, StreamRequest};

// 华为云：Key 防盗链 auth_key 算法与阿里云一致，地址签名直接复用 AliyunLiveSigner
$huawei = new HuaweiLivePlatform(
    new HuaweiLiveConfig(
        pushDomain: 'push.huawei.example.com',
        pullDomain: 'pull.huawei.example.com',
        pushKey: '推流KEY', pullKey: '拉流KEY', callbackKey: '回调KEY',
    ),
    new RecordingConfig(bucket: 'records', region: 'cn-north-4'),
);
echo $huawei->pushUrl(new StreamRequest('live001'))->url;   // rtmp://.../live001?auth_key=...

// 视频号：RTMP 推流（流名即开播码），回调用 md5(排序参数+token) 轻量验签
$wx = new WeixinChannelsPlatform(
    new WeixinChannelsConfig(callbackToken: 'your-token'),
    new RecordingConfig(bucket: 'records', region: 'local'),
);
echo $wx->pushUrl(new StreamRequest('your-stream-key'))->url;
```

> 注：华为云 / 视频号回调的字段名与签名串拼接方式可能随控制台配置不同；本驱动采用通用
> 方案并暴露 `callbackKey` / `callbackToken` 参数，接入时请以你自己的控制台回调配置为准对齐。
> 视频号权威的微信消息加解密（msg_signature + AES）应在微信开放平台侧完成，本驱动仅做
> 「配置驱动地址拼装 + 回调归一化」层。

### 5c. 快手直播

```php
use Kode\Live\LiveStreaming\Kuaishou\{KuaishouConfig, KuaishouPlatform};
use Kode\Live\Support\Dto\{RecordingConfig, StreamRequest};
use Kode\Live\Support\Enum\StreamProtocol;

// 快手真实推流地址与播放鉴权串由开放平台 API 下发，接入时用官方 SDK 取回覆盖默认域名
$ks = new KuaishouPlatform(
    new KuaishouConfig(callbackSecret: 'your-callback-secret'),
    new RecordingConfig(bucket: 'records', region: 'local'),
);
echo $ks->pushUrl(new StreamRequest('your-stream-key'))->url;   // rtmp://live-push.kuaishou.com/live/your-stream-key
echo $ks->pullUrl(new StreamRequest('your-stream-key'), StreamProtocol::Flv)->url;
```

### 5d. 七牛 / 又拍云 / Cloudflare / 声网 Agora

这四个平台同样遵循「配置驱动地址拼装 + 回调归一化」约定：`LiveManager` 不内置任何硬编码驱动，
统一通过 `register()` / `extend()` 注入，**无需改动核心**。

```php
use Kode\Live\LiveManager;
use Kode\Live\LiveStreaming\Qiniu\{QiniuConfig, QiniuPlatform};
use Kode\Live\LiveStreaming\Upyun\{UpyunConfig, UpyunPlatform};
use Kode\Live\LiveStreaming\Cloudflare\{CloudflareConfig, CloudflarePlatform};
use Kode\Live\LiveStreaming\Agora\{AgoraConfig, AgoraPlatform};
use Kode\Live\Support\Dto\{RecordingConfig, StreamRequest};
use Kode\Live\Support\Enum\{Platform, StreamProtocol};

// 七牛（hub 即 AppName）：rtmp://pili-publish.qiniu.com/{hub}/{stream}
$qiniu = new QiniuPlatform(
    new QiniuConfig(hub: 'my-hub', callbackSecret: 'your-callback-secret'),
    new RecordingConfig(bucket: 'records', region: 'local'),
);
echo $qiniu->pushUrl(new StreamRequest('stream-1'))->url;        // rtmp://pili-publish.qiniu.com/my-hub/stream-1
echo $qiniu->pullUrl(new StreamRequest('stream-1'), StreamProtocol::Hls)->url;

// 又拍云（推送域名为 服务名.uplive-upaiyun.com）
$upyun = new UpyunPlatform(
    new UpyunConfig(pushDomain: 'svc.uplive-upaiyun.com', callbackSecret: 'your-callback-secret'),
    new RecordingConfig(bucket: 'records', region: 'local'),
);
echo $upyun->pushUrl(new StreamRequest('stream-1'))->url;        // rtmp://svc.uplive-upaiyun.com/stream-1

// Cloudflare Stream（RTMPS 摄入 + HLS 播放，路径含 manifest.m3u8）
$cf = new CloudflarePlatform(
    new CloudflareConfig(callbackSecret: 'your-callback-secret'),
    new RecordingConfig(bucket: 'records', region: 'local'),
);
echo $cf->pushUrl(new StreamRequest('live-input-key'))->url;     // rtmps://live.cloudflare.com:443/live/live-input-key
echo $cf->pullUrl(new StreamRequest('video-id'), StreamProtocol::Hls)->url;

// 声网 Agora「推流到 CDN」：rtmp://push.agora.io/{app}/{stream}
$agora = new AgoraPlatform(
    new AgoraConfig(callbackSecret: 'your-callback-secret'),
    new RecordingConfig(bucket: 'records', region: 'local'),
);
echo $agora->pushUrl(new StreamRequest('channel-1', appName: 'live'))->url; // rtmp://push.agora.io/live/channel-1

// 统一注入管理器，按枚举取用
$manager = (new LiveManager())
    ->register($qiniu)
    ->extend(Platform::Cloudflare, fn () => $cf); // 惰性构建

$manager->driver(Platform::Qiniu)->pushUrl(new StreamRequest('stream-1'));
```

> 注：四家平台真实的播放鉴权串、推流地址（七牛 publishToken / 又拍云 token / Cloudflare Live Input
> STREAM_KEY / Agora RTC Token）均由各自开放平台 API 下发，接入时请用官方 SDK 取回后覆盖默认域名 /
> 密钥。本包驱动只做「配置驱动地址拼装 + 回调归一化（通用 md5 方案）」；若回调网关使用各平台原生
> 签名，请在本类 `parseWebhook()` 中替换为对应校验，或前置一个签名转换网关。

### 6. 传输层（SRT Transporter）

SRT 是低延迟可靠传输协议，常用于跨公网把编码器输出稳定地推到源站。它属于「字节收发」的传输层，
与 `LivePlatform` 负责的「推流地址拼装 + Webhook 回调」不在同一抽象层，因此本包把它独立为
`Transporter` 抽象，由 `TransporterManager` 按 scheme 路由——既有的直播 / 录制 / 回放 / 事件体系
完全不受影响。

```php
use Kode\Live\Transporter\{ExternalSrtTransporter, SrtUrl, TransporterManager, TransporterOptions};

// 平台驱动负责拼出 SRT 摄入地址（srt://host:port?...），真正的字节收发交给 Transporter
$ingest = SrtUrl::fromString('srt://srt-ingest.example.com:9000?mode=caller&passphrase=secret&latency=200');

// 参考实现：通过系统已安装的 srt-live-transmit（或 ffmpeg）外进程完成传输
$manager = TransporterManager::default();           // 已注册 srt -> ExternalSrtTransporter
$result = $manager->transmit(
    source: 'udp://0.0.0.0:1234',                    // 本地源（文件 / UDP / 另一个 SRT 地址）
    destination: $ingest,
    options: new TransporterOptions(
        reconnectAttempts: 3,                        // 瞬时失败（进程非零退出）指数退避重连
        logger: $psrLogger,                          // 可选：重连时记录 warning
    ),
);

if (!$result->success) {
    // 软失败以结果表达，硬错误（连外层 shell 都无法启动）才抛 TransportException
    report($result->error);
}
```

要点：

- `SrtUrl` 是纯地址值对象（`fromString()` / `__toString()` 互转），仅做主机名 / 端口格式校验，
  不做 SSRF 拦截——SRT 是你自主管控的传输通道（常指向内网编码网关），与「下载器拉取外部 URL」的
  威胁模型不同。
- `ExternalSrtTransporter` 不绑定任何 PHP SRT C 扩展，保证可移植；如需零拷贝原生实现，另写一个
  实现 `Transporter` 接口的类（如基于 ext-srt 的 `SocketTransporter`），通过
  `TransporterManager::register()` 注册即可。
- 重连复用核心的 `Support\Retry`（指数退避 + 抖动），与下载器、云 API 调用共享同一套重试语义。

## 开发

```bash
composer check   # = php-cs-fixer(dry-run) + phpstan level 8 + phpunit
```

工程规则（对所有 AI 编码助手 / IDE 生效）与平台扩展指引以**本地文件**形式提供：编辑器规则见
`.cursorrules` / `.cursor/rules`，平台扩展见 `.workbuddy/skills`；仅本地保留、不纳入仓库，
克隆后按需自行补充。

## License

MIT
