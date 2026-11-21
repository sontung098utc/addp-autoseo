<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Auth;

/**
 * Tải nhiều ảnh một lượt qua MediaService::storeMedia (magic bytes, nén WebP, dedupe, dùng chung file).
 * Ảnh mới vào "Chờ phân loại"; ảnh đã có (trùng nội dung) không bị đổi gì.
 */
class MediaUploadBatch
{
    public function __construct(protected MediaService $media) {}

    /**
     * @param  array<int, mixed>  $files  UploadedFile / đường dẫn
     * @param  array{website_id?:?int,kind?:?string,folder_id?:?int}  $opts
     * @param  array<int,int|string>  $tagIds
     * @return array{new:int,existing:int,failed:array<int,string>,first:?\App\Models\MediaAsset}
     */
    public function run(array $files, array $opts = [], array $tagIds = []): array
    {
        $r = ['new' => 0, 'existing' => 0, 'failed' => [], 'first' => null];
        foreach ($files as $file) {
            $name = is_object($file) && method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : 'ảnh';
            try {
                $asset = $this->media->storeMedia($file, ['website_id' => $opts['website_id'] ?? null, 'uploaded_by' => Auth::id()]);
            } catch (\Throwable $e) {
                $r['failed'][] = $name.': '.$e->getMessage();

                continue;
            }
            $r['first'] ??= $asset;
            if (! $asset->wasRecentlyCreated) {
                $r['existing']++;

                continue;
            }
            $r['new']++;
            $asset->update(array_filter(['status' => 'inbox', 'kind' => $opts['kind'] ?? null, 'folder_id' => $opts['folder_id'] ?? null], fn ($v) => filled($v)));
            if ($tagIds !== []) {
                $asset->tagModels()->syncWithoutDetaching($tagIds);
            }
        }

        return $r;
    }

    /** Nội dung thông báo kết quả. */
    public static function summary(array $r): array
    {
        $body = [];
        if ($r['existing'] > 0) {
            $body[] = "{$r['existing']} ảnh đã có trong thư viện (không tạo thêm).";
        }
        foreach ($r['failed'] as $f) {
            $body[] = 'Lỗi: '.$f;
        }

        return ["Đã tải {$r['new']} ảnh mới", implode("\n", $body) ?: null];
    }
}
