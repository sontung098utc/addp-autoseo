<?php

namespace App\Filament\Support\Review;

use App\Models\Article;
use App\Models\ArticleVersion;

/**
 * Thông tin "bản này đã bị từ chối" của bài. Nhãn dữ liệu vẫn là workflow_status = 'rejected' (hiển thị "Cần sửa");
 * lớp này chỉ giải thích rõ: bản nào, vì sao, ai từ chối, và bản mới nhất còn là bản đó (chưa sửa gì) hay không.
 */
final class RejectionInfo
{
    /** Bản MỚI NHẤT của bài nếu nó đang ở trạng thái bị từ chối (chưa có bản mới hơn), ngược lại null. */
    public static function unrevised(Article $article): ?ArticleVersion
    {
        if ($article->workflow_status !== 'rejected') {
            return null;
        }
        $latest = $article->versions()->with('reviewer')->orderByDesc('version')->first();

        return $latest && $latest->review_status === 'rejected' ? $latest : null;
    }

    /** @return array{version:int, reason:string, by:?string, at:?\Carbon\CarbonInterface, revised:bool}|null  null nếu bài không ở trạng thái Cần sửa */
    public static function for(Article $article): ?array
    {
        if ($article->workflow_status !== 'rejected') {
            return null;
        }
        $v = $article->versions()->with('reviewer')->where('review_status', 'rejected')->orderByDesc('version')->first();
        if (! $v) {
            return null;
        }
        $latestNo = (int) $article->versions()->max('version');

        return [
            'version' => (int) $v->version,
            'reason' => trim((string) $v->review_notes) !== '' ? trim((string) $v->review_notes) : 'không ghi lý do',
            'by' => $v->reviewer?->name,
            'at' => $v->reviewed_at,
            'revised' => $latestNo > (int) $v->version,
        ];
    }

    /** Nhãn nói rõ cho bài Cần sửa: "Cần sửa (đã từ chối bản v2)"; null nếu không áp dụng. */
    public static function label(Article $article): ?string
    {
        $i = self::for($article);

        return $i ? 'Cần sửa (đã từ chối bản v'.$i['version'].')' : null;
    }
}
