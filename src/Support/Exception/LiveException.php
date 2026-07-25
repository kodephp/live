<?php

declare(strict_types=1);

namespace Kode\Live\Support\Exception;

use RuntimeException;

/**
 * 本包所有异常的基类。捕获此类即可捕获包内一切错误。
 *
 * 约定：异常 message 中禁止出现任何密钥 / 鉴权串等敏感信息。
 */
abstract class LiveException extends RuntimeException
{
}
