<?php

namespace App\Filament\Support\Planning;

use App\Filament\Resources\ArticleResource;
use App\Filament\Support\StatusPresenter;
use App\Models\Article;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * MỘT nơi duy nhất quyết định "từ khóa chính này đã có bài trên website chưa" cho mọi lối tạo bài
 * (tạo tay, nhập file/kế hoạch, chiến dịch, Từ khóa → Thêm vào kế hoạch, tạo từ cụm).
 * Không có ràng buộc UNIQUE ở DB (không thêm migration), nên chặn ở tầng dịch vụ: kiểm lại trong giao dịch,
 * và tuần tự hóa các lần tạo cùng website + từ khóa bằng Cache::lock (Cache không ghi được thì bỏ qua khóa).
 */
final class KeywordDuplicateGuard
{
    /** Trạng thái bài cũ cho phép tạo lại (có xác nhận): bài đã bị trả về "Cần sửa". */
    public const REOPENABLE = ['rejected'];

    /** Gộp khoảng trắng, hạ chữ thường, chuẩn hóa Unicode NFC (chữ có dấu gõ kiểu tổ hợp = dựng sẵn). */
    public static function normalize(string $keyword): string
    {
        $k = trim((string) preg_replace('/\s+/u', ' ', $keyword));
        if (class_exists(\Normalizer::class)) {
            $k = \Normalizer::normalize($k, \Normalizer::FORM_C) ?: $k;
        }

        return mb_strtolower($k);
    }

    /** Bài đã dùng từ khóa này trên website (null nếu chưa). Mọi trạng thái đều tính. */
    public static function find(Website|int $website, string $keyword): ?Article
    {
        $websiteId = $website instanceof Website ? $website->id : $website;
        $n = self::normalize($keyword);
        if ($n === '') {
            return null;
        }

        return Article::where('website_id', $websiteId)->get(['id', 'primary_keyword', 'workflow_status'])
            ->first(fn (Article $a) => self::normalize((string) $a->primary_keyword) === $n);
    }

    public static function isReopenable(Article $a): bool
    {
        return in_array($a->workflow_status, self::REOPENABLE, true);
    }

    /** @return array{id:int,keyword:string,status:string,status_label:string,url:?string,reopenable:bool} */
    public static function describe(Article $a): array
    {
        $url = null;
        try {
            $url = ArticleResource::getUrl('view', ['record' => $a]);
        } catch (Throwable) {
        }

        return [
            'id' => $a->id,
            'keyword' => (string) $a->primary_keyword,
            'status' => (string) $a->workflow_status,
            'status_label' => StatusPresenter::label((string) $a->workflow_status, 'article'),
            'url' => $url,
            'reopenable' => self::isReopenable($a),
        ];
    }

    /**
     * Chạy $create trong giao dịch, sau khi giữ khóa theo website + từ khóa chuẩn hóa. $create phải tự gọi find() lại
     * bên trong để hai lần bấm nhanh liên tiếp không tạo hai bài. Khóa lỗi/hết hạn chờ thì chạy không khóa (vẫn có giao dịch).
     *
     * @template T
     *
     * @param  callable():T  $create
     * @return T
     */
    public static function serialized(Website|int $website, string $keyword, callable $create): mixed
    {
        $websiteId = $website instanceof Website ? $website->id : $website;
        $lock = null;
        try {
            $lock = Cache::lock('kwdup:'.$websiteId.':'.md5(self::normalize($keyword)), 15);
            $lock->block(5);
        } catch (Throwable) {
            $lock = null; // Cache không ghi được / chờ quá lâu: bỏ qua khóa, không ném lỗi.
        }
        try {
            return DB::transaction($create);
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
            }
        }
    }
}
