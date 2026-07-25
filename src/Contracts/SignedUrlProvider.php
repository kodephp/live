<?php

declare(strict_types=1);

namespace Kode\Live\Contracts;

/**
 * 对象存储签名 URL 提供者。
 *
 * 设计意图：本包不硬依赖任何 COS/OSS SDK。回放与下载需要访问对象存储里的录制文件时，
 * 由调用方注入一个实现（内部可用官方 SDK 生成预签名 URL），把「存储」这件事留在使用方手里。
 */
interface SignedUrlProvider
{
    /**
     * 为对象存储中的某个对象生成带签名的临时访问 URL。
     *
     * @param string $bucket 桶名
     * @param string $objectKey 对象键
     * @param int $ttlSeconds 有效期（秒）
     */
    public function presign(string $bucket, string $objectKey, int $ttlSeconds): string;
}
