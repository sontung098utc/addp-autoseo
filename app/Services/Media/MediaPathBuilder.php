<?php

namespace App\Services\Media;

/** Đường dẫn lưu trữ theo địa chỉ nội dung: một sha256 = một thư mục, dùng chung cho mọi website. */
class MediaPathBuilder
{
    public static function dir(string $sha256): string
    {
        $sha = strtolower($sha256);
        if (! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new \InvalidArgumentException('sha256 không hợp lệ.');
        }

        return 'media/cas/'.substr($sha, 0, 2).'/'.$sha;
    }

    /** Tên file duy nhất theo nội dung (CMS lấy basename làm tên file tải lên, nên không dùng tên cố định). */
    public static function fileName(string $sha256): string
    {
        return substr(strtolower($sha256), 0, 16).'.webp';
    }

    public static function thumbName(string $sha256): string
    {
        return substr(strtolower($sha256), 0, 16).'_thumb.webp';
    }

    public static function original(string $sha256): string
    {
        return self::dir($sha256).'/'.self::fileName($sha256);
    }

    public static function thumb(string $sha256): string
    {
        return self::dir($sha256).'/'.self::thumbName($sha256);
    }

    public static function isCanonical(string $path): bool
    {
        return (bool) preg_match('#^media/cas/[a-f0-9]{2}/[a-f0-9]{64}/#', $path);
    }
}
