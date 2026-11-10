<?php

namespace App\Filament\Support\Article;

use App\Models\AgentRunStep;
use App\Models\ArticleVersion;

/** Dữ liệu cho resources/views/filament/article/images-gallery.blade.php. Chỉ đọc, không gọi mạng. */
final class ImageGalleryData
{
    /** @return list<array{id:int,url:string,thumb:string,role:string,alt:string,ai:bool,prompt:?string,model:?string,cost_vnd:?float}> */
    public static function forVersion(ArticleVersion $v): array
    {
        $out = [];
        foreach ($v->mediaAssets()->orderByPivot('is_featured', 'desc')->orderByPivot('position')->get() as $a) {
            $m = (array) $a->metadata;
            $ai = ($m['ai_generated'] ?? false) === true;
            $out[] = [
                'id' => $a->id, 'url' => $a->url, 'thumb' => (string) $a->thumbnail_url,
                'role' => $a->pivot->is_featured ? 'featured' : 'inline',
                'alt' => (string) ($a->pivot->custom_alt ?: $a->alt_text),
                'ai' => $ai, 'prompt' => $ai ? ($m['prompt'] ?? null) : null, 'model' => $ai ? ($m['model'] ?? null) : null,
                'cost_vnd' => $ai && isset($m['cost_vnd']) ? (float) $m['cost_vnd'] : null,
            ];
        }

        return $out;
    }

    /** Lý do bước 'images' gần nhất của phiên bản này không tạo ảnh (hoặc lỗi); null nếu chưa có bước ảnh. */
    public static function skipReason(ArticleVersion $v): ?string
    {
        $steps = AgentRunStep::query()->where('stage', 'images')
            ->whereHas('run', fn ($q) => $q->where('article_id', $v->article_id))->orderByDesc('id')->limit(10)->get();
        $step = $steps->first(fn ($s) => (int) ($s->data['version_id'] ?? 0) === $v->id);

        return $step ? ($step->data['skipped_reason'] ?? ($step->status === 'warn' ? $step->detail : null)) : null;
    }
}
