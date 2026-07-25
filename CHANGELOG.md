# Changelog

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

### Added
- 初始版本：多平台直播综合包骨架（PHP 8.3+）。
- 契约层 `Contracts`：`LivePlatform`、`LiveEvent`、`Downloader`、`SignedUrlProvider`。
- 腾讯云 CSS 驱动：推拉流地址签名、回放地址、回调验签（`md5(key+t)`）与录制事件解析。
- 阿里云直播驱动：URL 鉴权 A 方式、回放地址、回调令牌校验与录制事件解析。
- `ResumableDownloader`：基于 HTTP Range 的断点续传下载器，支持重试与路径安全校验。
- `LiveManager` 驱动管理器与 `LivePipeline` 编排（回调 → 回放 → 下载归档）。
- 通用 IDE 规则（`AGENTS.md` / `.cursorrules` / `.cursor/rules` / Copilot）与项目级 skills。
- 质量门禁：PHPStan level 8、PSR-12（php-cs-fixer）、PHPUnit（16 用例全绿）。
