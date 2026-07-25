# AGENTS.md — kode/live 工程规则（通用 IDE / AI 编码规则）

> 本文件是本仓库对**所有 AI 编码助手与 IDE**（Cursor / Copilot / WorkBuddy / Windsurf / Cline 等）的唯一权威规则来源。
> 其它入口（`.cursorrules`、`.cursor/rules/*.mdc`、`.github/copilot-instructions.md`）均只做一句话转述并指向本文件。
> 修改工程约定时，**只改本文件**，避免多处漂移。

---

## 1. 项目是什么

`kode/live` 是一个 **多平台直播综合 PHP 包（PHP 8.3+）**，聚焦四件事：

1. **推拉流地址**：为各直播平台生成带鉴权签名的推流 / 拉流地址（RTMP/SRT/FLV/HLS）。
2. **录制落存储**：直播录制由**云厂商侧自动落对象存储**（COS/OSS 等）。本包**不做独立存储抽象层**，存储只是「驱动的一个 bucket 配置」。
3. **回放**：从已录制文件生成可播放地址（VOD/HLS，通常是带签名的临时 URL）。
4. **下载**：从对象存储直链 / 签名 URL 断点续传取回录制文件。

用 **Pipeline** 把「开播 → 录制(自动落桶) → 回放地址 → 下载」串起来。各模块**事件驱动、面向接口、零耦合**。

### 关键设计决策（不要推翻，除非需求变更）

- ❌ 不引入 Flysystem 之类的多后端存储抽象——云厂商已内置录制落存储。
- ✅ 存储 = 直播驱动内的 `RecordingConfig`（bucket / region / 路径模板 / 格式 / 切片时长）。
- ✅ 回放 / 下载所需的**对象存储签名 URL**通过可选的 `SignedUrlProvider` 回调委托出去，核心包**不硬依赖** COS/OSS SDK，保持精简。
- ✅ 全部**面向 `Contracts/` 接口编程**；新增平台 = 新增一个实现类，不改核心。

---

## 2. 技术栈与硬约束

- **PHP `>=8.3`**，`declare(strict_types=1);` 为每个 PHP 文件第一行（`<?php` 之后）。
- 命名空间根 `Kode\Live\`，PSR-4 映射 `src/`；测试 `Kode\Live\Tests\` → `tests/`。
- 依赖：`guzzlehttp/guzzle`（HTTP）、`psr/log`、`psr/event-dispatcher`、`psr/http-message`、`psr/clock`。
- 代码风格：`@PSR12` + `@PHP83Migration`（见 `.php-cs-fixer.dist.php`）。
- 静态分析：**PHPStan level 8**，不得降级；新增代码不得引入新错误。
- 测试：PHPUnit 11，纯单元测试**不得发起真实网络请求**（用 mock handler）。

---

## 3. 目录与分层（架构地图）

```
src/
├── Contracts/          # 接口层：一切实现都要落到这里的接口
├── Support/
│   ├── Dto/            # 只读值对象（readonly），跨层传递数据
│   ├── Enum/           # 协议 / 格式 / 事件类型等枚举
│   ├── Event/          # 领域事件（StreamStarted / RecordingReady ...）
│   ├── Exception/      # 异常体系，全部继承 LiveException
│   ├── Http/           # HTTP 客户端封装（基于 Guzzle）
│   └── Signature/      # 各平台 URL 签名算法（纯函数、可单测）
├── LiveStreaming/
│   ├── AbstractLivePlatform.php   # 平台驱动基类，收敛公共逻辑
│   ├── Tencent/                   # 腾讯云 CSS 驱动
│   └── Aliyun/                    # 阿里云直播驱动
├── Recording/          # 录制配置与录制任务/文件查询
├── Playback/           # 回放地址生成
├── Download/           # 断点续传下载器
├── Pipeline/           # 编排：把上面几件事串成一条流水线
└── LiveManager.php     # 门面 / 驱动管理器（对外主入口）
```

**依赖方向（严格单向，禁止反向依赖）**：
`Contracts` ← `Support` ← `LiveStreaming` / `Recording` / `Playback` / `Download` ← `Pipeline` ← `LiveManager`
上层可依赖下层；下层**绝不**依赖上层。

---

## 4. 编码规范（必须遵守）

1. **面向接口**：新功能先在 `Contracts/` 定义接口，再写实现。调用方只依赖接口。
2. **DTO 只读**：`Support/Dto` 一律 `final readonly class`，构造器属性提升，不可变。
3. **枚举优先**：协议、格式、事件类型、平台名等用 `enum`，禁止裸字符串魔法值。
4. **异常明确**：所有异常继承 `Support\Exception\LiveException`；对外错误信息不泄露密钥。
5. **强类型**：参数、返回值、属性全部显式类型；避免 `mixed`，必要时用泛型 phpdoc（`@return list<Recording>`）。
6. **不可变优先**：能 `readonly` 就 `readonly`；配置对象用 `withXxx()` 复制而非原地改。
7. **时间来源**：需要当前时间时注入 `Psr\Clock\ClockInterface`，禁止在业务里直接 `time()`（便于测试）。
8. **日志**：注入 `Psr\Log\LoggerInterface`，默认 `NullLogger`；禁止 `var_dump`/`echo` 调试残留。
9. **秘钥安全**：`SecretKey`/`AccessKey` 等敏感字段禁止出现在日志、异常 message、`__toString()`、序列化输出中。
10. **无副作用构造**：构造函数不做网络 IO；IO 只发生在显式方法调用时。

---

## 5. 安全红线（Security）

- 签名密钥仅存于内存配置对象，**永不**写日志 / 异常 / 缓存文件。
- 生成的推流地址含鉴权串，视为敏感信息，不得打印到标准输出。
- Webhook / 回调解析**必须验签**后才信任 payload（`parseWebhook` 内先校验签名）。
- 下载器写入路径必须校验，禁止路径穿越（`..`）；只允许写入调用方显式指定的目录。
- 所有外部输入（平台回调、下载响应头）都视为不可信，做类型与边界校验。
- HTTP 请求必须设超时（默认连接 5s / 总 30s），禁止无限等待。

---

## 6. 新增一个直播平台驱动（高频操作）

> 详细分步见 `.workbuddy/skills/add-live-platform/SKILL.md`。要点：

1. 在 `Support/Enum/Platform.php` 增加枚举项。
2. `src/LiveStreaming/<Vendor>/<Vendor>Platform.php` 继承 `AbstractLivePlatform`，实现 `Contracts\LivePlatform`。
3. 签名算法放 `Support/Signature/<Vendor>Signer.php`，写成**纯函数**并配单测。
4. 在 `LiveManager` 的驱动工厂注册（或通过 `extend()` 外部注册）。
5. 补 `tests/Unit/<Vendor>PlatformTest.php`，覆盖签名与 URL 生成（用固定时间戳断言）。
6. 更新 README 的「支持平台」表格。

---

## 7. 提交与质量门禁

- 每次改完运行：`composer check`（= cs 检查 + phpstan + phpunit），全绿才算完成。
- 提交信息用祈使句、简洁：`feat(tencent): add SRT pull url signing`。
- 不提交 `vendor/`、`composer.lock`（库项目）、缓存与 `.DS_Store`（见 `.gitignore`）。
- 不新增未使用的文件 / 类 / 依赖；发现重复或废弃代码**顺手删除**。

---

## 8. AI 助手协作约定

- 动手前先读本文件与相关 `Contracts/` 接口，保持分层不被破坏。
- 拿不准的**架构决策**（新增依赖、跨层调整、破坏兼容）先问人，不要擅自扩大改动面。
- 只做被要求的改动；不顺手重排无关代码、不无意义地重命名。
- 生成代码必须能通过 `phpstan level 8` 与 `php-cs-fixer`，不要产出「示意性」半成品。
