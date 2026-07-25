<?php

declare(strict_types=1);

namespace Kode\Live\Support\Dto;

use Kode\Live\Support\Enum\RecordingFormat;
use Kode\Live\Support\Exception\ConfigurationException;

/**
 * 录制落对象存储的配置。
 *
 * 这是本包对「存储」的唯一建模：录制由云厂商侧自动完成，我们只描述「录到哪个桶、什么格式、怎么切片」。
 * 存储桶本身不由本包读写；回放 / 下载所需的签名 URL 通过 SignedUrlProvider 委托。
 */
final readonly class RecordingConfig
{
    /**
     * @param string $bucket 对象存储桶名
     * @param string $region 存储区域（如 ap-guangzhou / oss-cn-hangzhou）
     * @param string $pathTemplate 录制文件对象键模板，支持占位符 {app}{stream}{date}{time}{ext}
     * @param RecordingFormat $format 录制格式
     * @param int $sliceSeconds 单文件切片时长（秒），0 表示不切片
     */
    public function __construct(
        public string $bucket,
        public string $region,
        public string $pathTemplate = '{app}/{stream}/{date}/{time}{ext}',
        public RecordingFormat $format = RecordingFormat::Mp4,
        public int $sliceSeconds = 0,
    ) {
        if ($bucket === '') {
            throw ConfigurationException::missing('recording.bucket');
        }
        if ($region === '') {
            throw ConfigurationException::missing('recording.region');
        }
        if ($sliceSeconds < 0) {
            throw ConfigurationException::invalid('recording.sliceSeconds', '不能为负数');
        }
    }

    /**
     * 按模板渲染出对象键（object key）。
     *
     * @param array<string, string> $vars 覆盖或补充的占位符变量
     */
    public function resolveObjectKey(string $app, string $stream, array $vars = []): string
    {
        $defaults = [
            '{app}' => $app,
            '{stream}' => $stream,
            '{date}' => date('Ymd'),
            '{time}' => date('His'),
            '{ext}' => $this->format->extension(),
        ];

        /** @var array<string, string> $replacements */
        $replacements = array_merge($defaults, $vars);

        return strtr($this->pathTemplate, $replacements);
    }
}
