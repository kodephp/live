# GitHub Copilot Instructions — kode/live

本仓库的完整工程规则要点如下（规则文件自包含；亦见 `.cursorrules` 与 `.cursor/rules/kode-live.mdc`）：

- 多平台直播综合包，PHP 8.3+；每个文件 `declare(strict_types=1);`，命名空间 `Kode\Live\`（PSR-4 → `src/`）。
- 面向 `Contracts/` 接口编程；DTO 一律 `final readonly class`；协议/格式/事件用 `enum`，禁止魔法值。
- **不做独立存储抽象层**：录制由云厂商自动落对象存储，存储只是驱动的 `RecordingConfig`（bucket 等）；回放/下载签名 URL 通过 `SignedUrlProvider` 委托。
- 分层依赖单向：`Contracts ← Support ← 功能模块 ← Pipeline ← LiveManager`。
- 安全：密钥不入日志/异常/输出；webhook 先验签；下载防路径穿越；HTTP 必设超时。
- 门禁：PHPStan level 8、PSR-12、PHPUnit 全绿（`composer check`）。
- 新增平台驱动按 `.workbuddy/skills/add-live-platform/SKILL.md` 执行。
