<?php

namespace App\Services\Brain;

use App\Models\Brain;
use App\Models\BrainProfile;
use App\Models\BrandProfile;
use App\Models\KnowledgeTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AG-B1: tạo/sửa Trí tuệ, Hồ sơ lĩnh vực, cài đặt. Quyền (TẠM, "quyền duyệt tính sau"): admin + approver sửa; biên tập viên chỉ xem.
 * Mọi chuỗi người dùng nhập được cắt độ dài; không lưu HTML (strip_tags) vì hồ sơ sẽ vào prompt và vào giao diện.
 */
final class BrainService
{
    public static function canManage(?User $u): bool
    {
        return KnowledgeReview::canManage($u);
    }

    private function authorize(?User $by): void
    {
        if (! self::canManage($by)) {
            throw new KnowledgeError('Bạn chưa có quyền sửa Kho trí tuệ.');
        }
    }

    /**
     * @param list<int|Website> $websites website liên kết (thứ tự = mức ưu tiên)
     */
    public function createFromTemplate(string $name, ?string $templateKey, array $websites, ?User $by, bool $skipAuth = false): Brain
    {
        if (! $skipAuth) {
            $this->authorize($by);
        }
        $name = trim(mb_substr(strip_tags($name), 0, 160));
        if ($name === '') {
            throw new \InvalidArgumentException('Nhập tên Trí tuệ.');
        }
        $key = $templateKey === null || $templateKey === '' ? 'trong' : $templateKey;
        $tpl = BrainTemplates::get($key) ?? throw new \InvalidArgumentException('Mẫu trí tuệ không tồn tại.');

        return DB::transaction(function () use ($name, $key, $tpl, $websites, $by) {
            $brain = Brain::create([
                'name' => $name, 'slug' => $this->uniqueSlug($name), 'domain_key' => $tpl['domain_key'], 'template_key' => $key, 'language' => 'vi',
                'description' => $tpl['description'], 'status' => 'active',
                'settings' => array_filter(['ai_reliance' => $tpl['ai_reliance'], 'risk_class' => $tpl['risk_class'], 'reliance_reason' => $tpl['reliance_reason'] ?? null, 'suggested_sources' => $tpl['suggested_sources'] ?? null, 'sample_label' => ! empty($tpl['suggested_sources']) ? self::SAMPLE_LABEL : null], fn ($v) => $v !== null),
                'created_by' => $by?->id,
            ]);
            $p = $tpl['profile'];
            $brain->profile()->create(['voice' => $p['voice'], 'structure' => $p['structure'], 'terminology' => $p['terminology'], 'reasoning' => $p['reasoning'], 'rubric_notes' => $p['rubric_notes'], 'exemplars' => $p['exemplars'], 'updated_by' => $by?->id]);
            foreach ($tpl['topics'] as $parent => $children) {
                $top = KnowledgeTopic::create(['brain_id' => $brain->id, 'name' => mb_substr($parent, 0, 120)]);
                foreach ($children as $child) {
                    KnowledgeTopic::create(['brain_id' => $brain->id, 'parent_id' => $top->id, 'name' => mb_substr($child, 0, 120)]);
                }
            }
            $this->syncWebsites($brain, $websites, $by, false);
            // AG-B2: Trí tuệ Thương hiệu gắn đúng một website thì tự có hồ sơ doanh nghiệp RỖNG của website đó (nếu chưa có). Không đụng hồ sơ đã có.
            if ($key === 'thuong_hieu' && $brain->websites()->count() === 1) {
                BrandProfile::query()->firstOrCreate(['website_id' => $brain->websites()->value('websites.id')], ['updated_by' => $by?->id]);
            }

            return $brain->load('profile');
        });
    }

    public const SAMPLE_LABEL = 'Mẫu: chưa có kiến thức nào được duyệt';

    /**
     * AG-B2: cài các Trí tuệ từ mẫu. Idempotent theo (template_key + tên): đã có thì BỎ QUA, không ghi đè bản đã sửa, không tạo trùng.
     * Không tự gắn website (trừ khi truyền $site). Không tạo nguồn/mục kiến thức nào: chỉ khung.
     *
     * @param list<string>|null $keys null = 14 mẫu sức khỏe
     * @return array{created:list<Brain>,skipped:list<string>,unknown:list<string>}
     */
    public function installTemplates(?array $keys = null, ?Website $site = null, ?User $by = null): array
    {
        // Dòng lệnh/seeder không có người dùng (null) được phép; giao diện chỉ admin. Người duyệt chỉ xem.
        if ($by !== null && ! ($by->is_active && $by->isAdmin())) {
            throw new KnowledgeError('Chỉ quản trị viên được cài các trí tuệ mẫu.');
        }
        $keys ??= BrainTemplates::HEALTH_KEYS;
        $out = ['created' => [], 'skipped' => [], 'unknown' => []];
        foreach (array_values(array_unique($keys)) as $key) {
            $tpl = BrainTemplates::get($key);
            if ($tpl === null) {
                $out['unknown'][] = (string) $key;

                continue;
            }
            if (Brain::query()->where('template_key', $key)->where('name', $tpl['name'])->exists()) {
                $out['skipped'][] = $key;

                continue;
            }
            $out['created'][] = $this->createFromTemplate($tpl['name'], $key, $site ? [$site] : [], $by, true);
        }

        return $out;
    }

    /**
     * AG-B2: Trí tuệ Thương hiệu của một website (tạo nếu chưa có). Dùng cho URL cũ /admin/ho-so-doanh-nghiep và bước cài đặt.
     * Chọn Trí tuệ thương hiệu đang hoạt động ưu tiên cao nhất đã gắn website; không có thì tạo mới "Thương hiệu – {tên website}" (cần quyền sửa).
     */
    public function brandBrainFor(Website $site, ?User $by): ?Brain
    {
        $existing = $this->findBrandBrain($site);
        if ($existing || ! self::canManage($by)) {
            return $existing;
        }

        return $this->createFromTemplate('Thương hiệu – '.$site->name, 'thuong_hieu', [$site], $by);
    }

    /** Trí tuệ Thương hiệu đang hoạt động ưu tiên cao nhất của website (không tạo mới). */
    public function findBrandBrain(Website $site): ?Brain
    {
        return Brain::query()->where('template_key', 'thuong_hieu')->where('status', 'active')
            ->whereHas('websites', fn ($q) => $q->where('websites.id', $site->id))
            ->join('brain_website', fn ($j) => $j->on('brain_website.brain_id', '=', 'brains.id')->where('brain_website.website_id', $site->id))
            ->orderBy('brain_website.priority')->orderBy('brains.id')->select('brains.*')->first();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tri-tue';
        $slug = $base;
        for ($i = 2; Brain::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /** @param list<int|Website> $websites thứ tự = ưu tiên (10, 20, …) */
    public function syncWebsites(Brain $brain, array $websites, ?User $by, bool $authorize = true): void
    {
        if ($authorize) {
            $this->authorize($by);
        }
        $ids = collect($websites)->map(fn ($w) => $w instanceof Website ? $w->id : (int) $w)->filter()->unique()->values();
        $valid = Website::query()->whereIn('id', $ids)->pluck('id')->all();
        $sync = [];
        foreach ($ids->filter(fn ($id) => in_array($id, $valid, true))->values() as $i => $id) {
            $sync[$id] = ['priority' => ($i + 1) * 10];
        }
        $brain->websites()->sync($sync);
    }

    /**
     * Liên kết website kèm mức ưu tiên riêng (số nhỏ = ưu tiên trước). Trí tuệ không còn website nào vẫn hợp lệ (chỉ dùng được khi chiến dịch chọn).
     *
     * @param array<int,int|string> $priorityById website_id => ưu tiên
     */
    public function linkWebsites(Brain $brain, array $priorityById, ?User $by): void
    {
        $this->authorize($by);
        $valid = Website::query()->whereIn('id', array_keys($priorityById))->pluck('id')->all();
        $sync = [];
        foreach ($priorityById as $id => $pr) {
            if (in_array((int) $id, $valid, true)) {
                $sync[(int) $id] = ['priority' => max(1, min(1000, (int) $pr))];
            }
        }
        $brain->websites()->sync($sync);
    }

    /** Cài đặt: mức dựa vào AI, hạng rủi ro. */
    public function saveSettings(Brain $brain, string $reliance, string $risk, ?User $by): Brain
    {
        $this->authorize($by);
        if (! array_key_exists($reliance, Brain::RELIANCE) || ! array_key_exists($risk, Brain::RISK)) {
            throw new \InvalidArgumentException('Giá trị cài đặt không hợp lệ.');
        }
        $brain->update(['settings' => array_merge((array) $brain->settings, ['ai_reliance' => $reliance, 'risk_class' => $risk])]);

        return $brain;
    }

    public function setArchived(Brain $brain, bool $archived, ?User $by): Brain
    {
        $this->authorize($by);
        $brain->update(['status' => $archived ? 'archived' : 'active']);

        return $brain;
    }

    /**
     * Lưu Hồ sơ lĩnh vực. Bài mẫu tối đa BrainProfile::MAX_EXEMPLARS (vượt thì từ chối, không tự cắt âm thầm).
     *
     * @param array<string,mixed> $data voice, structure, terminology, reasoning, rubric_notes, exemplars
     */
    public function saveProfile(Brain $brain, array $data, ?User $by): BrainProfile
    {
        $this->authorize($by);
        $exemplars = array_values(array_filter((array) ($data['exemplars'] ?? []), fn ($e) => is_array($e) && (trim((string) ($e['title'] ?? '')) !== '' || trim((string) ($e['text_or_url'] ?? '')) !== '')));
        if (count($exemplars) > BrainProfile::MAX_EXEMPLARS) {
            throw new \InvalidArgumentException('Chỉ lưu tối đa '.BrainProfile::MAX_EXEMPLARS.' bài mẫu (mẫu ít mà đồng nhất giọng cho kết quả tốt hơn).');
        }
        $v = (array) ($data['voice'] ?? []);
        $voice = [
            'tone' => $this->s($v['tone'] ?? '', 200), 'formality' => $this->s($v['formality'] ?? '', 120), 'pronouns' => $this->s($v['pronouns'] ?? '', 200),
            'do' => $this->list($v['do'] ?? [], 15, 200), 'dont' => $this->list($v['dont'] ?? [], 15, 200),
        ];
        $types = [];
        foreach (array_slice((array) (($data['structure'] ?? [])['article_types'] ?? []), 0, 12) as $t) {
            $t = (array) $t;
            $nameT = $this->s($t['name'] ?? '', 120);
            if ($nameT === '') {
                continue;
            }
            $types[] = ['key' => Str::slug($t['key'] ?? '') ?: (Str::slug($nameT) ?: 'loai-bai'), 'name' => $nameT, 'outline' => $this->list($t['outline'] ?? [], 20, 160)];
        }
        $pref = [];
        foreach (array_slice((array) (($data['terminology'] ?? [])['preferred'] ?? []), 0, 60) as $t) {
            $t = (array) $t;
            $term = $this->s($t['term'] ?? '', 120);
            if ($term !== '') {
                $pref[] = ['term' => $term, 'definition' => $this->s($t['definition'] ?? '', 300), 'avoid' => $this->list($t['avoid'] ?? [], 8, 80)];
            }
        }
        $ex = [];
        foreach ($exemplars as $e) {
            $ex[] = ['title' => $this->s($e['title'] ?? '', 160), 'text_or_url' => mb_substr(trim(strip_tags((string) ($e['text_or_url'] ?? ''))), 0, 6000), 'note' => $this->s($e['note'] ?? '', 300)];
        }
        $profile = $brain->profile ?? new BrainProfile(['brain_id' => $brain->id]);
        $profile->fill([
            'voice' => $voice, 'structure' => ['article_types' => $types],
            'terminology' => ['preferred' => $pref, 'abbreviations' => $this->list((($data['terminology'] ?? [])['abbreviations'] ?? []), 40, 120)],
            'reasoning' => ['rules' => $this->list((($data['reasoning'] ?? [])['rules'] ?? []), 20, 300)],
            'rubric_notes' => mb_substr(trim(strip_tags((string) ($data['rubric_notes'] ?? ''))), 0, 2000),
            'exemplars' => $ex, 'updated_by' => $by?->id,
        ])->save();
        $brain->setRelation('profile', $profile);

        return $profile;
    }

    private function s(mixed $v, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)) ?? ''), 0, $max);
    }

    /** @return list<string> */
    private function list(mixed $v, int $maxItems, int $maxLen): array
    {
        $out = [];
        foreach ((array) $v as $x) {
            $x = $this->s(is_scalar($x) ? $x : '', $maxLen);
            if ($x !== '' && ! isset($out[mb_strtolower($x)])) {
                $out[mb_strtolower($x)] = $x;
            }
        }

        return array_slice(array_values($out), 0, $maxItems);
    }
}
