<?php

namespace App\Services\Brain;

use App\Models\Brain;
use App\Models\BrainSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeError;
use Illuminate\Support\Collection;

/**
 * AG-B3 (Kho trí tuệ P2, Tầng 3): Sổ nguồn uy tín. Quản lý (thêm, xếp bậc, xác nhận, chặn, xóa) và PHÂN LOẠI một chuỗi trích dẫn.
 * Nguyên tắc: hệ thống KHÔNG tự cho rằng một tên miền uy tín. Chỉ nguồn do người có quyền xác nhận (có bậc) hoặc chặn mới có hiệu lực;
 * gợi ý từ mẫu chỉ là `suggested` và không bao giờ được tính.
 */
final class BrainSourceRegistry
{
    /** @var Collection<int,BrainSource> */
    private Collection $effective;

    /** @param Collection<int,BrainSource>|null $sources nguồn có hiệu lực (đã xác nhận + có bậc); null = rỗng */
    public function __construct(?Collection $sources = null)
    {
        $this->effective = ($sources ?? collect())->filter(fn (BrainSource $s) => $s->isEffective())->values();
    }

    /** @param list<int> $brainIds */
    public static function forBrains(array $brainIds): self
    {
        if ($brainIds === []) {
            return new self();
        }

        return new self(BrainSource::query()->whereIn('brain_id', $brainIds)->where('status', 'confirmed')->whereNotNull('tier')->get());
    }

    public function isEmpty(): bool
    {
        return $this->effective->isEmpty();
    }

    /** @return Collection<int,BrainSource> nguồn dùng được để trích (không gồm nguồn bị chặn) */
    public function citable(): Collection
    {
        return $this->effective->filter(fn (BrainSource $s) => $s->tier !== 'blocked')->values();
    }

    /**
     * Phân loại một chuỗi trích dẫn (URL, tên miền hoặc tên tổ chức) theo sổ. Null = không có trong sổ (không được tính).
     * Nguồn bị chặn thắng nguồn khác nếu cùng khớp.
     *
     * @return array{tier:string,source:BrainSource}|null
     */
    public function classify(string $cited): ?array
    {
        $cited = trim($cited);
        if ($cited === '') {
            return null;
        }
        $host = self::hostOf($cited);
        $name = KnowledgeDedupe::normalize($cited);
        $hits = $this->effective->filter(function (BrainSource $s) use ($host, $name) {
            if ($host !== null && $s->domain && ($host === $s->domain || str_ends_with($host, '.'.$s->domain))) {
                return true;
            }

            return $name !== '' && KnowledgeDedupe::normalize($s->name) === $name;
        });
        if ($hits->isEmpty()) {
            return null;
        }
        $pick = $hits->firstWhere('tier', 'blocked') ?? $hits->sortBy(fn (BrainSource $s) => (int) $s->tier)->first();

        return ['tier' => (string) $pick->tier, 'source' => $pick];
    }

    /** Tên miền chuẩn hóa từ URL hoặc chuỗi giống tên miền; null nếu không phải. */
    public static function hostOf(string $v): ?string
    {
        $v = trim(mb_strtolower($v));
        if ($v === '' || preg_match('/\s/u', $v)) {
            return null;
        }
        $host = parse_url(str_contains($v, '://') ? $v : 'http://'.$v, PHP_URL_HOST);
        $host = is_string($host) ? preg_replace('/^www\./', '', rtrim($host, '.')) : null;

        return $host && preg_match('/^(?=.{3,190}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host) ? $host : null;
    }

    // ---- Quản lý (cần quyền sửa Kho trí tuệ) ----

    private function authorize(?User $by): void
    {
        if (! BrainService::canManage($by)) {
            throw new KnowledgeError('Bạn chưa có quyền sửa Sổ nguồn uy tín.');
        }
    }

    private function cleanTier(?string $tier): ?string
    {
        if ($tier === null || $tier === '') {
            return null;
        }
        if (! array_key_exists($tier, BrainSource::TIERS)) {
            throw new KnowledgeError('Bậc nguồn không hợp lệ.');
        }

        return $tier;
    }

    /** Thêm nguồn. Có bậc thì coi như người dùng xác nhận luôn; không có bậc thì chỉ là gợi ý. */
    public function add(Brain $brain, string $name, string $domain, ?string $tier, ?string $note, ?User $by): BrainSource
    {
        $this->authorize($by);
        $name = trim(mb_substr(strip_tags($name), 0, 190));
        $host = self::hostOf($domain);
        if ($name === '') {
            throw new KnowledgeError('Nhập tên nguồn.');
        }
        if ($host === null) {
            throw new KnowledgeError('Tên miền không hợp lệ (ví dụ: who.int).');
        }
        if (BrainSource::query()->where('brain_id', $brain->id)->where('domain', $host)->exists()) {
            throw new KnowledgeError('Tên miền này đã có trong sổ.');
        }
        $tier = $this->cleanTier($tier);
        $row = BrainSource::create([
            'brain_id' => $brain->id, 'name' => $name, 'domain' => $host, 'tier' => $tier, 'status' => $tier ? 'confirmed' : 'suggested',
            'note' => ($note = trim(mb_substr(strip_tags((string) $note), 0, 500))) !== '' ? $note : null,
            'confirmed_by' => $tier ? $by?->id : null, 'confirmed_at' => $tier ? now() : null, 'created_by' => $by?->id,
        ]);

        return $row;
    }

    /** Xếp bậc và xác nhận (hoặc chặn) một nguồn đã có. Bậc là bắt buộc. */
    public function confirm(BrainSource $source, string $tier, ?User $by): BrainSource
    {
        $this->authorize($by);
        $tier = $this->cleanTier($tier) ?? throw new KnowledgeError('Chọn bậc cho nguồn trước khi xác nhận.');
        $source->update(['tier' => $tier, 'status' => 'confirmed', 'confirmed_by' => $by?->id, 'confirmed_at' => now()]);

        return $source->refresh();
    }

    /** Rút xác nhận: nguồn trở về "chưa xác nhận", không còn được tính. */
    public function unconfirm(BrainSource $source, ?User $by): BrainSource
    {
        $this->authorize($by);
        $source->update(['tier' => null, 'status' => 'suggested', 'confirmed_by' => null, 'confirmed_at' => null]);

        return $source->refresh();
    }

    public function remove(BrainSource $source, ?User $by): void
    {
        $this->authorize($by);
        $source->delete();
    }

    /** Nhập gợi ý nguồn của mẫu (brains.settings.suggested_sources) thành hàng `suggested`. Chạy lại được, không đụng hàng đã có. */
    public function importSuggested(Brain $brain): int
    {
        $n = 0;
        foreach ((array) ($brain->settings['suggested_sources'] ?? []) as $s) {
            $host = self::hostOf((string) ($s['domain'] ?? ''));
            $name = trim(mb_substr((string) ($s['org'] ?? ''), 0, 190));
            if ($host === null || $name === '') {
                continue;
            }
            $row = BrainSource::query()->firstOrCreate(['brain_id' => $brain->id, 'domain' => $host], ['name' => $name, 'status' => 'suggested']);
            $n += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $n;
    }
}
