<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;

/**
 * Báo cáo dọn dẹp thư viện ảnh. CHỈ ĐỌC và gợi ý: không xóa file, không xóa bản ghi.
 * Hành động duy nhất được phép (do người duyệt bấm) là chuyển ảnh sang "Đã lưu trữ".
 */
class MediaHousekeeping
{
    /** @return array{missing:array<int,array>,orphan_files:array<int,string>,unused:array<int,array>,duplicates:array<int,array>,total:int} */
    public function report(int $unusedDays = 90, string $disk = 'public'): array
    {
        $fs = Storage::disk($disk);
        $assets = MediaAsset::query()->where('disk', $disk)->get(['id', 'title', 'file_path', 'thumbnail_path', 'metadata', 'sha256', 'status', 'created_at', 'file_size']);

        $referenced = [];
        $keepDirs = [];
        $missing = [];
        foreach ($assets as $a) {
            $paths = array_filter([$a->file_path, $a->thumbnail_path, $a->metadata['cas_path'] ?? null, $a->metadata['cas_thumb_path'] ?? null]);
            foreach ($paths as $p) {
                $referenced[(string) $p] = true;
                if (MediaPathBuilder::isCanonical((string) $p)) {
                    $keepDirs[dirname((string) $p)] = true; // thư mục nội dung (gồm các biến thể sinh lười)
                }
            }
            if (! $fs->exists((string) $a->file_path)) {
                $missing[] = ['id' => $a->id, 'title' => $a->title, 'file_path' => $a->file_path];
            }
        }

        $orphans = [];
        if ($fs->exists('media')) {
            foreach ($fs->allFiles('media') as $f) {
                if (isset($referenced[$f]) || isset($keepDirs[dirname($f)])) {
                    continue;
                }
                $orphans[] = $f;
            }
        }

        $unused = MediaAsset::query()->where('disk', $disk)->where('status', '!=', 'archived')
            ->where('created_at', '<', now()->subDays($unusedDays))
            ->whereDoesntHave('articleVersions')->orderBy('created_at')->limit(500)
            ->get(['id', 'title', 'file_size', 'created_at'])
            ->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'file_size' => (int) $a->file_size, 'created_at' => (string) $a->created_at])->all();

        $dups = [];
        foreach ($assets->groupBy('sha256') as $sha => $group) {
            $files = $group->pluck('file_path')->unique();
            if ($files->count() > 1) { // cùng nội dung nhưng nhiều file riêng (ảnh cũ trước khi dùng chung file)
                $dups[] = ['sha256' => $sha, 'ids' => $group->pluck('id')->all(), 'files' => $files->values()->all()];
            }
        }

        return ['missing' => $missing, 'orphan_files' => $orphans, 'unused' => $unused, 'duplicates' => $dups, 'total' => $assets->count()];
    }

    /** Lưu trữ (không xóa) các ảnh chưa dùng trong danh sách báo cáo. Trả về số ảnh đã lưu trữ. */
    public function archiveUnused(int $unusedDays = 90): int
    {
        $ids = array_column($this->report($unusedDays)['unused'], 'id');

        return $ids === [] ? 0 : MediaAsset::query()->whereIn('id', $ids)->whereDoesntHave('articleVersions')->update(['status' => 'archived']);
    }
}
