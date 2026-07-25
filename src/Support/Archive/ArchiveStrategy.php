<?php

declare(strict_types=1);

namespace Kode\Live\Support\Archive;

use Kode\Live\Support\Event\RecordingReadyEvent;

/**
 * 归档策略接口：决定「录制完成事件」应当落地到本地哪个路径。
 *
 * 与具体下载实现解耦——流水线只关心「目标路径是什么」，由 ResumableDownloader
 * 负责真正把文件取回。这样归档位置既能用模板统一生成，也能按需自定义
 * （例如按业务分桶、写入对象存储、或挂载到 NAS）。
 */
interface ArchiveStrategy
{
    /**
     * 为某个录制完成事件计算本地归档目标路径。
     */
    public function destinationFor(RecordingReadyEvent $event): string;
}
