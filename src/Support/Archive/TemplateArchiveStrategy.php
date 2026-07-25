<?php

declare(strict_types=1);

namespace Kode\Live\Support\Archive;

use Kode\Live\Support\Clock\SystemClock;
use Kode\Live\Support\Event\RecordingReadyEvent;
use Psr\Clock\ClockInterface;

/**
 * 基于占位符模板的归档策略。
 *
 * 模板中可用占位符（缺失则原样保留）：
 *   {date}      当前日期，格式 Y/m/d（按注入时钟，便于测试）
 *   {streamName} 流名
 *   {objectKey}  录制文件在对象存储中的完整 key
 *   {baseName}  objectKey 的文件名部分（无扩展名时回退为 {streamName}.mp4）
 *   {bucket}     录制落入的存储桶
 *
 * 例：'/data/records/{date}/{streamName}/{baseName}'
 */
final class TemplateArchiveStrategy implements ArchiveStrategy
{
    public function __construct(
        private readonly string $template,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    public function destinationFor(RecordingReadyEvent $event): string
    {
        $objectKey = $event->recording->objectKey;
        $baseName = basename($objectKey);
        if ($baseName === '') {
            $baseName = $event->streamName() . '.mp4';
        }

        return str_replace(
            ['{date}', '{streamName}', '{objectKey}', '{baseName}', '{bucket}'],
            [
                $this->clock->now()->format('Y/m/d'),
                $event->streamName(),
                $objectKey,
                $baseName,
                $event->recording->bucket,
            ],
            $this->template,
        );
    }
}
