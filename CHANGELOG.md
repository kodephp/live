# Changelog

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/)。版本号由 git tag 驱动（`vX.Y.Z`），发布时打 tag 并推送。

## [0.2.0] - 2026-07-25

### Added
- 新增「基于 stream-key 的驱动基类」`AbstractStreamKeyPlatform`，收敛推拉流地址拼装逻辑，新增同类平台只需补域名与 webhook 验签。
- 通用 RTMP 驱动（`rtmp`）：面向自建 SRS / nginx-rtmp，配置驱动推拉流地址，支持 `?key=` 鉴权与 SRS `on_publish` / `on_dvr` / `on_record_done` 回调解析。
- B站（哔哩哔哩）直播驱动（`bilibili`）：内置默认推拉流域名，FLV / HLS 拉流，回调采用 `md5(排序参数+secret)` 验签。
- 抖音直播驱动（`douyin`）：内置默认 CDN 推拉流域名，FLV / HLS 拉流，回调采用 `md5(排序参数+secret)` 验签。
- 三个平台对应的单元测试（推拉流地址 + webhook 正/负用例），PHPUnit 总用例升至 32。

### Changed
- `Platform` 枚举新增 `Rtmp` / `Bilibili` / `Douyin` 三项及中文标签。

## [0.1.0] - 2026-07-25

### Added
- 初始版本：多平台直播综合包骨架（PHP 8.3+）。
- 契约层 `Contracts`：`LivePlatform`、`LiveEvent`、`Downloader`、`SignedUrlProvider`。
- 腾讯云 CSS 驱动：推拉流地址签名、回放地址、回调验签（`md5(key+t)`）与录制事件解析。
- 阿里云直播驱动：URL 鉴权 A 方式、回放地址、回调令牌校验与录制事件解析。
- `ResumableDownloader`：基于 HTTP Range 的断点续传下载器，支持重试与路径安全校验。
- `LiveManager` 驱动管理器与 `LivePipeline` 编排（回调 → 回放 → 下载归档）。
- 通用 IDE 规则（`AGENTS.md` / `.cursorrules` / `.cursor/rules` / Copilot）与项目级 skills。
- 质量门禁：PHPStan level 8、PSR-12（php-cs-fixer）、PHPUnit（16 用例全绿）。
