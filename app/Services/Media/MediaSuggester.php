<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaCollection;
use Illuminate\Support\Str;

/**
 * Gợi ý phân loại theo luật đơn giản (không gọi AI, không đọc nội dung web). Kết quả là JSON có cấu trúc,
 * lưu ở metadata.suggestion; chỉ khi người duyệt bấm "Áp dụng" mới ghi vào loại/thư mục/thẻ thật.
 */
class MediaSuggester
{
    /** @return array{kind:string,folder_id:?int,tags:array<int,string>,reasons:array<int,string>} */
    public function suggest(MediaAsset $a): array
    {
        $reasons = [];
        $w = (int) $a->width;
        $h = (int) $a->height;
        $origMime = (string) ($a->metadata['original_mime'] ?? '');
        $kind = 'photo';

        if ($w > 0 && $h > 0) {
            if ($h >= 1.6 * $w) {
                $kind = 'infographic';
                $reasons[] = 'Ảnh dọc rất cao (tỉ lệ '.round($h / $w, 1).':1) thường là infographic';
            } elseif ($origMime === 'image/png' && abs($w / $h - 16 / 9) < 0.12 && $w >= 1000) {
                $kind = 'screenshot';
                $reasons[] = 'PNG tỉ lệ 16:9 kích thước lớn thường là ảnh chụp màn hình';
            } elseif (max($w, $h) <= 600 && abs($w / $h - 1) < 0.15) {
                $kind = 'logo_brand';
                $reasons[] = 'Ảnh vuông nhỏ thường là logo';
            }
        }

        // Thư mục: tên thư mục xuất hiện trong tiêu đề/tên file gốc/thẻ
        $hay = Str::lower(Str::ascii((string) $a->title.' '.(string) $a->original_name.' '.implode(' ', (array) $a->tags)));
        $folderId = null;
        $best = 0;
        foreach (MediaCollection::query()->get(['id', 'name']) as $c) {
            $needle = Str::lower(Str::ascii($c->name));
            if (mb_strlen($needle) >= 3 && str_contains($hay, $needle) && mb_strlen($needle) > $best) {
                $best = mb_strlen($needle);
                $folderId = $c->id;
            }
        }
        if ($folderId) {
            $reasons[] = 'Tiêu đề/tên file nhắc tới thư mục "'.MediaCollection::find($folderId)?->name.'"';
        }

        $tags = collect((array) $a->tags)->filter(fn ($t) => is_string($t) && trim($t) !== '')->map(fn ($t) => trim($t))->unique()->values()->all();

        return ['kind' => $kind, 'folder_id' => $folderId, 'tags' => $tags, 'reasons' => $reasons];
    }

    /** Tính và lưu gợi ý (chưa áp dụng). */
    public function store(MediaAsset $a): array
    {
        $s = $this->suggest($a);
        $a->update(['metadata' => array_merge((array) $a->metadata, ['suggestion' => $s])]);

        return $s;
    }

    /** Người duyệt áp dụng gợi ý: kiểm tra lại giá trị rồi ghi, xóa gợi ý. */
    public function apply(MediaAsset $a): bool
    {
        $s = (array) ($a->metadata['suggestion'] ?? []);
        if ($s === []) {
            return false;
        }
        $update = [];
        if (in_array($s['kind'] ?? null, MediaAsset::KINDS, true)) {
            $update['kind'] = $s['kind'];
        }
        if (! empty($s['folder_id']) && MediaCollection::query()->whereKey($s['folder_id'])->exists()) {
            $update['folder_id'] = (int) $s['folder_id'];
        }
        $meta = (array) $a->metadata;
        unset($meta['suggestion']);
        $update['metadata'] = $meta;
        $a->update($update);

        return true;
    }

    public function dismiss(MediaAsset $a): void
    {
        $meta = (array) $a->metadata;
        unset($meta['suggestion']);
        $a->update(['metadata' => $meta]);
    }
}
