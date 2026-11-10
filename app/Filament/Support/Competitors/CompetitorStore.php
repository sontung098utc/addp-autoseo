<?php

namespace App\Filament\Support\Competitors;

use App\Filament\Support\Keywords\CsvKeywordParser;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Models\Competitor;
use App\Models\Keyword;
use App\Models\KeywordGap;
use App\Models\User;
use App\Models\Website;
use App\Services\Brand\BrandProfileData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * AG-S24: kho đối thủ + khoảng trống THẬT (bảng competitors, keyword_gaps). Màn Đối thủ dùng bản này khi không ở
 * chế độ minh họa. Không truy cập mạng; không điền số giả (volume/điểm NULL khi thiếu dữ liệu).
 */
final class CompetitorStore
{
    /** Bật bản thật khi không ở chế độ minh họa (production) hoặc đặt STUDIO_COMPETITORS_REAL. */
    public static function useReal(): bool
    {
        return ! \App\Filament\Support\Demo\DemoMode::enabled() || (bool) config('studio.competitors_real');
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Competitor> */
    public static function competitors(Website $w)
    {
        return Competitor::where('website_id', $w->id)->orderBy('domain')->get();
    }

    public static function add(Website $w, User $by, string $domainInput, ?string $brandName, string $kind, ?string $note = null): Competitor
    {
        if (! array_key_exists($kind, Competitor::KINDS)) {
            throw new InvalidArgumentException('Loại đối thủ không hợp lệ.');
        }
        $existing = Competitor::where('website_id', $w->id)->pluck('domain')->all();
        $domain = CompetitorDomain::validate($domainInput, $existing, $w->base_url);
        $brand = BrandProfileData::text($brandName ?? '', 120);
        $note = BrandProfileData::text($note ?? '', 500);

        return Competitor::create([
            'website_id' => $w->id, 'domain' => $domain, 'brand_name' => $brand !== '' ? $brand : null,
            'kind' => $kind, 'note' => $note !== '' ? $note : null, 'added_by' => $by->id,
        ]);
    }

    public static function remove(Website $w, int $competitorId): void
    {
        Competitor::where('website_id', $w->id)->where('id', $competitorId)->delete();
        self::recompute($w);
    }

    /** @return array{rows: list<array>, errors: list<array>, duplicates: list<array>, fatal: ?string} */
    public static function previewCsv(Website $w, string $content): array
    {
        $comps = Competitor::where('website_id', $w->id)->pluck('id', 'domain')->all();
        $existing = [];
        foreach (KeywordGap::where('website_id', $w->id)->get(['competitor_id', 'keyword']) as $g) {
            $existing[($g->competitor_id ?? 0).'|'.CsvKeywordParser::key($g->keyword)] = true;
        }

        return (new CsvGapParser)->parse($content, $comps, $existing, $w->base_url);
    }

    /** @param list<array> $rows kết quả CsvGapParser::parse()['rows'] */
    public static function importRows(Website $w, array $rows, string $source = 'csv'): int
    {
        $n = 0;
        DB::transaction(function () use ($w, $rows, $source, &$n) {
            foreach ($rows as $r) {
                try {
                    KeywordGap::create([
                        'website_id' => $w->id, 'competitor_id' => $r['competitor_id'], 'keyword' => $r['keyword'],
                        'competitor_rank' => $r['competitor_rank'], 'our_rank' => $r['our_rank'], 'volume' => $r['volume'],
                        'source' => $source, 'status' => 'moi',
                    ]);
                    $n++;
                } catch (QueryException) {
                    // trùng khóa duy nhất: bỏ qua, đã được báo là trùng ở bước xem trước
                }
            }
            self::recompute($w);
        });

        return $n;
    }

    /** Tính lại điểm ưu tiên cho khoảng trống còn mở. Không có volume => điểm để trống. */
    public static function recompute(Website $w): void
    {
        $open = KeywordGap::where('website_id', $w->id)->where('status', 'moi')->get();
        $total = Competitor::where('website_id', $w->id)->count();
        $maxVolume = (int) ($open->max('volume') ?? 0);
        $ranked = [];
        foreach ($open as $g) {
            if ($g->competitor_id !== null) {
                $ranked[CsvKeywordParser::key($g->keyword)][$g->competitor_id] = true;
            }
        }
        foreach ($open as $g) {
            $s = PriorityScorer::scoreReal($g->volume, count($ranked[CsvKeywordParser::key($g->keyword)] ?? []), $total, $maxVolume);
            $score = $s['score'] ?? null;
            if ($g->priority_score !== $score) {
                $g->update(['priority_score' => $score]);
            }
        }
    }

    /**
     * Thêm khoảng trống vào kho Từ khóa (nguồn 'competitor'), không trùng khóa chuẩn hóa; nối mọi dòng khoảng trống
     * cùng từ khóa của website. Từ khóa đã có thì chỉ nối, không tạo bản sao.
     */
    public static function addKeyword(Website $w, User $by, int $gapId): Keyword
    {
        $gap = KeywordGap::where('website_id', $w->id)->findOrFail($gapId);
        if ($gap->status === 'da_them_tu_khoa' && $gap->keyword_id) {
            throw new InvalidArgumentException('Khoảng trống này đã được thêm vào từ khóa.');
        }
        $name = CsvKeywordParser::normalizeKeyword($gap->keyword);
        $key = Keyword::keyFor($name);

        return DB::transaction(function () use ($w, $by, $gap, $name, $key) {
            $kw = Keyword::where('website_id', $w->id)->where('keyword_key', $key)->first();
            if (! $kw) {
                $kw = Keyword::create([
                    'website_id' => $w->id, 'keyword' => $name, 'keyword_key' => $key,
                    'volume' => KeywordGap::where('website_id', $w->id)->get()
                        ->filter(fn (KeywordGap $g) => PlannedArticleCreator::normalize($g->keyword) === $key)->max('volume'),
                    'difficulty' => null, 'intent' => 'informational', 'source' => 'competitor', 'status' => 'new', 'created_by' => $by->id,
                ]);
            }
            KeywordGap::where('website_id', $w->id)->get()
                ->filter(fn (KeywordGap $g) => PlannedArticleCreator::normalize($g->keyword) === $key)
                ->each(fn (KeywordGap $g) => $g->update(['status' => 'da_them_tu_khoa', 'keyword_id' => $kw->id, 'priority_score' => null]));

            return $kw;
        });
    }

    public static function ignore(Website $w, int $gapId): void
    {
        KeywordGap::where('website_id', $w->id)->where('id', $gapId)->where('status', 'moi')
            ->update(['status' => 'bo_qua', 'priority_score' => null]);
        self::recompute($w);
    }

    public static function restore(Website $w, int $gapId): void
    {
        KeywordGap::where('website_id', $w->id)->where('id', $gapId)->where('status', 'bo_qua')->update(['status' => 'moi']);
        self::recompute($w);
    }
}
