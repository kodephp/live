<?php

declare(strict_types=1);

namespace Kode\Live\Support\Enum;

/**
 * 直播平台回调（webhook）事件类型的归一化枚举。
 *
 * 各平台原始事件码不同，驱动的 parseWebhook() 负责映射到这里的统一枚举。
 */
enum EventType: string
{
    /** 推流开始 / 开播。 */
    case StreamStarted = 'stream_started';

    /** 推流结束 / 断流。 */
    case StreamEnded = 'stream_ended';

    /** 录制文件已生成并落入对象存储。 */
    case RecordingReady = 'recording_ready';

    /** 无法识别的事件。 */
    case Unknown = 'unknown';
}
