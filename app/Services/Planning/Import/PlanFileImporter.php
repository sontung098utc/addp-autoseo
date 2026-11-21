<?php

namespace App\Services\Planning\Import;

use App\Filament\Support\ContentPlan\PlanData;
use App\Filament\Support\ContentPlan\PlanRules;
use App\Filament\Support\Planning\DuplicateKeywordException;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Filament\Support\Schedule\VnTime;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Keywords\KeywordResearchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Biến các dòng của file + bản ánh xạ cột (đã được người dùng xác nhận) thành bài "Đã lên kế hoạch" và chiến dịch THẬT.
 * Hai bước tách bạch: build() chỉ TÍNH và báo từng dòng (dùng cho xem trước, không ghi gì); apply() mới ghi.
 * Mọi giá trị ô được chuẩn hóa lại ở đây, bất kể AI đã nói gì; từ khóa trùng (trong file hoặc đã có bài trên website) bị chặn và báo riêng.
 */
final class PlanFileImporter
{
    public const MAX_ITEMS = 500;

    public const TARGET_BY_COLUMN = 'by_column';

    public const TARGET_NEW = 'new';

    public const TARGET_SOLO = 'solo';

    /**
     * @param  list<list<string>>  $rows  các dòng của sheet (chỉ số + 1 = số dòng thật)
     * @param  array<int,string>  $fields  chỉ số cột => trường
     * @param  list<int>  $sections  số dòng là dòng đề mục: tên nhóm (lấy từ chính ô đó) thành chiến dịch của các bài bên dưới, nếu dòng bài không có cột chiến dịch riêng
     * @param  list<int>  $skip  số dòng bỏ qua (không phải bài)
     * @param  list<array<string,mixed>>  $links  liên kết sang sheet khác đã qua PlanFileLinks::resolve (tra mã/tên ở cột của sheet chính để lấy tên, mô tả, ngày của chiến dịch...)
     * @return array{items:list<array<string,mixed>>, problems:list<array{row:int,keyword:string,reason:string}>, blank:int, over_limit:bool, unlinked:list<string>, link_hits:array<string,int>}
     */
    public function build(Website $site, array $rows, array $fields, int $headerRow, array $sections = [], array $skip = [], array $links = []): array
    {
        $byField = [];
        foreach ($fields as $idx => $f) {
            if ($f !== 'ignore' && in_array($f, PlanFileFields::keys(), true)) {
                $byField[$f][] = (int) $idx;
            }
        }
        if (empty($byField['primary_keyword'])) {
            throw new InvalidArgumentException('Chưa có cột nào được chọn là "Từ khóa chính". Chọn một cột làm từ khóa chính rồi thử lại.');
        }
        $kwCol = $byField['primary_keyword'][0];
        $existing = [];
        foreach (Article::query()->where('website_id', $site->id)->pluck('primary_keyword') as $k) {
            $existing[PlannedArticleCreator::normalize((string) $k)] = true;
        }
        // URL thật của bài đã đăng (theo từ khóa chính) để đưa vào chỉ dẫn cho người viết khi "Liên kết nội bộ nên có" khớp tới bài đó
        $urlOf = [];
        foreach (\App\Models\Publication::query()->where('website_id', $site->id)->where('status', 'published')->whereNotNull('published_url')->orderBy('id')->with('articleVersion.article')->get() as $pub) {
            $kwp = $pub->articleVersion?->article?->primary_keyword;
            if ($kwp && PlanBrief::safeUrl((string) $pub->published_url)) {
                $urlOf[PlannedArticleCreator::normalize((string) $kwp)] = (string) $pub->published_url;
            }
        }
        $first = fn (array $r, string $f): string => isset($byField[$f]) ? (string) ($r[$byField[$f][0]] ?? '') : '';

        $linkIndex = [];
        foreach ($links as $li => $L) {
            foreach (array_slice($L['rows'], $L['header']) as $lr) {
                $k = PlanFileLinks::key($lr[$L['key']] ?? '');
                if ($k !== '' && ! isset($linkIndex[$li][$k])) {
                    $linkIndex[$li][$k] = $lr;
                }
            }
        }
        $unlinked = [];
        $hits = [];
        $sectionSet = array_flip(array_map('intval', $sections));
        $skipSet = array_flip(array_map('intval', $skip));
        $headerCells = $headerRow > 0 ? array_map(fn ($c) => mb_strtolower(trim((string) $c)), $rows[$headerRow - 1] ?? []) : [];
        $section = null;
        $fileKeys = [];
        foreach ($rows as $fr) {
            $fk = PlanFileFields::text((string) ($fr[$kwCol] ?? ''), 255);
            if ($fk !== '') {
                $fileKeys[PlannedArticleCreator::normalize($fk)] = true;
            }
        }
        $items = [];
        $problems = [];
        $seen = [];
        $blank = 0;
        $over = false;
        $warnings = [];
        foreach ($rows as $i => $r) {
            $n = $i + 1;
            // dòng đề mục: chỉ nhận khi đúng một ô có chữ (kiểm lại ở đây, không tin hoàn toàn danh sách đưa vào)
            $filled = array_filter($r, fn ($c) => trim((string) $c) !== '');
            if (isset($sectionSet[$n]) && count($filled) === 1 && $n !== $headerRow) {
                $section = PlanFileFields::text((string) reset($filled), 120) ?: null;

                continue;
            }
            if ($n <= $headerRow || isset($skipSet[$n])) {
                continue;
            }
            // dòng tiêu đề cột lặp lại giữa bảng (thường gặp khi mỗi nhóm có một hàng tiêu đề riêng) không phải là bài
            if ($headerCells !== [] && count($filled) >= 2 && count(array_filter($filled, fn ($c, $k) => mb_strtolower(trim((string) $c)) === ($headerCells[$k] ?? null), ARRAY_FILTER_USE_BOTH)) >= count($filled) * 0.6) {
                continue;
            }
            // dòng VÍ DỤ của file mẫu ("(VÍ DỤ, xóa dòng này)") người dùng quên xóa: bỏ qua im lặng, không tạo bài mẫu
            if (preg_match('/\(\s*v[íi]\s*d[ụu][^)]*x[óo]a d[òo]ng n[àa]y/iu', implode(' ', array_map('strval', $r)))) {
                continue;
            }
            $kw = PlanFileFields::text((string) ($r[$kwCol] ?? ''), 255);
            if ($kw === '') {
                if (array_filter($r, fn ($c) => trim((string) $c) !== '') === []) {
                    $blank++;
                } else {
                    $problems[] = ['row' => $n, 'keyword' => '', 'reason' => 'Không có từ khóa chính'];
                }

                continue;
            }
            $key = PlannedArticleCreator::normalize($kw);
            if (mb_strlen($kw) > 190 || preg_match(KeywordResearchService::INJECTION, $kw)) {
                $problems[] = ['row' => $n, 'keyword' => $kw, 'reason' => 'Từ khóa không hợp lệ (quá dài hoặc giống chỉ dẫn)'];

                continue;
            }
            if (isset($seen[$key])) {
                $problems[] = ['row' => $n, 'keyword' => $kw, 'reason' => 'Trùng với dòng '.$seen[$key].' trong file'];

                continue;
            }
            if (isset($existing[$key])) {
                $problems[] = ['row' => $n, 'keyword' => $kw, 'reason' => 'Website đã có bài dùng từ khóa này'];

                continue;
            }
            if (count($items) >= self::MAX_ITEMS) {
                $over = true;

                break;
            }
            $seen[$key] = $n;

            $secondary = [];
            foreach ($byField['secondary_keywords'] ?? [] as $c) {
                $secondary = array_merge($secondary, PlanFileFields::splitList((string) ($r[$c] ?? '')));
            }
            $secondary = array_values(array_filter(array_unique($secondary), fn ($s) => PlannedArticleCreator::normalize($s) !== $key && ! preg_match(KeywordResearchService::INJECTION, $s)));

            // tra cứu sang sheet liên kết: chỉ điền các trường cấp chiến dịch/cụm/ghi chú; mã không tìm thấy thì giữ nguyên giá trị gốc
            $lk = ['campaign' => null, 'campaign_description' => null, 'campaign_start' => null, 'campaign_end' => null, 'topic_cluster' => null, 'planned_date' => null, 'priority' => null,
                'search_intent' => null, 'title' => null, 'secondary' => [], 'guidance' => []];
            foreach ($links as $li => $L) {
                $lkey = PlanFileLinks::key($r[$L['from']] ?? '');
                if ($lkey === '') {
                    continue;
                }
                $lrow = $linkIndex[$li][$lkey] ?? null;
                if ($lrow === null) {
                    if (array_intersect($L['fields'], PlanFileLinks::STRICT_FIELDS) !== []) {
                        $unlinked[$L['sheet'].': '.PlanFileFields::text((string) ($r[$L['from']] ?? ''), 40)] = true;
                    }

                    continue;
                }
                $hits[$L['sheet']] = ($hits[$L['sheet']] ?? 0) + 1;
                foreach ($L['fields'] as $col => $f) {
                    $v = PlanFileFields::text((string) ($lrow[$col] ?? ''), $f === 'campaign_description' ? 500 : 600);
                    if ($v === '') {
                        continue;
                    }
                    if ($f === 'guidance') {
                        $h = $L['header'] > 0 ? PlanFileFields::text((string) ($L['rows'][$L['header'] - 1][$col] ?? ''), 60) : '';
                        $lk['guidance'][] = $h !== '' ? $h.': '.$v : $v;
                    } elseif ($f === 'secondary_keywords') {
                        $lk['secondary'] = array_merge($lk['secondary'], PlanFileFields::splitList($v));
                    } elseif ($f === 'campaign_start' || $f === 'campaign_end') {
                        $lk[$f] ??= PlanFileFields::date($v);
                    } else {
                        $lk[$f] ??= $v; // giá trị tra được thay cho giá trị gốc trong cột (thường chính là mã dùng để tra)
                    }
                }
            }
            if ($lk['secondary'] !== []) {
                $secondary = array_values(array_filter(array_unique(array_merge($secondary, $lk['secondary'])), fn ($s) => PlannedArticleCreator::normalize($s) !== $key && ! preg_match(KeywordResearchService::INJECTION, $s)));
            }

            // BRIEF của bài (đối tượng, giọng văn, độ dài, cấu trúc, câu hỏi, nguồn, liên kết nội bộ...): ghép thành các dòng có nhãn đứng ĐẦU chỉ dẫn,
            // điều đo được (độ dài, bảng, ảnh, H2) đứng trước nhất để không bị cắt khi chỉ dẫn quá dài.
            $briefVals = [];
            foreach (PlanBrief::keys() as $bk) {
                $briefVals[$bk] = $first($r, $bk);
            }
            foreach (['must_sources', 'competitor_refs', 'internal_links'] as $rk) { // loại URL trỏ về địa chỉ nội bộ (không lưu, báo ở xem trước)
                $sp = PlanBrief::splitRefs($briefVals[$rk]);
                if ($sp['dropped'] !== []) {
                    $warnings[] = "dòng {$n} ({$kw}): bỏ ".count($sp['dropped']).' địa chỉ nội bộ/không an toàn ở cột «'.PlanBrief::FIELDS[$rk][0].'»';
                }
                $briefVals[$rk] = implode('; ', $sp['kept']);
            }
            // liên kết nội bộ: tên/từ khóa khớp bài đã có trên website hoặc bài khác trong file thì tính là khớp; URL không kiểm; còn lại là chưa có bài
            $linkReport = ['match' => 0, 'url' => 0, 'missing' => [], 'resolved' => 0];
            $linkOut = [];
            foreach (PlanBrief::splitRefs($briefVals['internal_links'])['kept'] as $lt) {
                $nk = PlannedArticleCreator::normalize($lt);
                if (preg_match('#^https?://#i', $lt)) {
                    $linkReport['url']++;
                } elseif (isset($existing[$nk]) || isset($fileKeys[$nk])) {
                    $linkReport['match']++;
                    if (isset($urlOf[$nk])) { // bài đã đăng: ghi kèm URL thật để người viết gắn đúng liên kết
                        $linkReport['resolved']++;
                        $lt .= ' ('.$urlOf[$nk].')';
                    }
                } else {
                    $linkReport['missing'][] = $lt;
                }
                $linkOut[] = $lt;
            }
            $briefVals['internal_links'] = implode('; ', $linkOut);
            $briefLines = PlanBrief::lines($briefVals);
            // brief có cấu trúc lưu cùng bài (JSON): các trường brief đã làm sạch + thông tin xuất bản; người duyệt khớp tài khoản theo email/tên
            $briefJson = array_filter(array_map(fn ($v) => trim((string) $v), $briefVals), fn ($v) => $v !== '');
            foreach (['cms_category' => 120, 'cms_author' => 120] as $ck => $max) {
                if (($cv = PlanFileFields::text($first($r, $ck), $max)) !== '') {
                    $briefJson[$ck] = $cv;
                }
            }
            if (($sl = Str::slug(PlanFileFields::text($first($r, 'cms_slug'), 120))) !== '') {
                $briefJson['cms_slug'] = $sl;
            }
            if (($rv = PlanFileFields::text($first($r, 'reviewer'), 120)) !== '') {
                $briefJson['reviewer'] = $rv;
                $ru = \App\Models\User::query()->where('is_active', true)->where(fn ($q) => $q->where('email', $rv)->orWhere('name', $rv))->first();
                if ($ru) {
                    $briefJson['reviewer_user_id'] = $ru->id;
                } else {
                    $warnings[] = "dòng {$n} ({$kw}): người duyệt «{$rv}» không khớp tài khoản nào, chỉ lưu tên";
                }
            }
            $briefFilled = array_keys(array_filter($briefVals, fn ($v) => trim((string) $v) !== ''));
            $notes = $briefLines;
            if (($t = PlanFileFields::text($lk['title'] ?? $first($r, 'title'), 200)) !== '') {
                $notes[] = 'Tiêu đề gợi ý: '.$t;
            }
            foreach ($byField['guidance'] ?? [] as $c) {
                $v = PlanFileFields::text((string) ($r[$c] ?? ''), 600);
                if ($v !== '') {
                    $h = $headerRow > 0 ? PlanFileFields::text((string) ($rows[$headerRow - 1][$c] ?? ''), 60) : '';
                    $notes[] = $h !== '' && count($byField['guidance']) > 1 ? $h.': '.$v : $v; // nhiều cột ghi chú: kèm tên cột để không lẫn vào nhau
                }
            }
            foreach ($lk['guidance'] as $g) {
                $notes[] = $g;
            }
            $fit = PlanBrief::fit($notes);
            $guidance = $fit['text'];
            if ($fit['cut']) {
                $warnings[] = "dòng {$n} ({$kw}): chỉ dẫn dài hơn ".PlanBrief::GUIDANCE_MAX.' ký tự nên phần cuối bị cắt (điều đo được như độ dài, bảng, ảnh luôn được giữ trước)';
            }
            if ($guidance !== '' && preg_match(KeywordResearchService::INJECTION, $guidance)) {
                $problems[] = ['row' => $n, 'keyword' => $kw, 'reason' => 'Ghi chú giống chỉ dẫn điều khiển AI nên bị chặn'];

                continue;
            }
            $dateRaw = $lk['planned_date'] ?? $first($r, 'planned_date');
            $date = PlanFileFields::date($dateRaw);

            $items[] = [
                'row' => $n,
                'keyword' => $kw,
                'secondary' => array_slice($secondary, 0, 8),
                'intent' => PlanFileFields::intent($lk['search_intent'] ?? $first($r, 'search_intent')),
                'cluster' => $lk['topic_cluster'] !== null ? mb_substr($lk['topic_cluster'], 0, 120) : (($c = PlanFileFields::text($first($r, 'topic_cluster'), 120)) !== '' ? $c : null), // giá trị tra được thay cho mã gốc trong cột
                'campaign' => $lk['campaign'] !== null ? mb_substr($lk['campaign'], 0, 120) : (($c = PlanFileFields::text($first($r, 'campaign'), 120)) !== '' ? $c : $section),
                'campaign_description' => $lk['campaign_description'] ?? PlanFileFields::text($first($r, 'campaign_description'), 500),
                'campaign_start' => $lk['campaign_start'] ?? PlanFileFields::date($first($r, 'campaign_start')),
                'campaign_end' => $lk['campaign_end'] ?? PlanFileFields::date($first($r, 'campaign_end')),
                'date' => $date,
                'date_invalid' => trim($dateRaw) !== '' && $date === null,
                'priority' => PlanFileFields::priority($lk['priority'] ?? $first($r, 'priority')),
                'volume' => PlanFileFields::volume($first($r, 'search_volume')),
                'difficulty' => PlanFileFields::difficulty($first($r, 'difficulty')),
                'cpc' => PlanFileFields::cpc($first($r, 'cpc')),
                'competition' => PlanFileFields::competition($first($r, 'competition')),
                'guidance' => $guidance !== '' ? $guidance : null,
                'brief' => $briefFilled,
                'brief_json' => $briefJson,
                'link_report' => $linkReport,
                'measurable' => $guidance !== '' ? PlanBrief::measurable($guidance) : [],
            ];
        }

        return ['items' => $items, 'problems' => $problems, 'warnings' => array_slice($warnings, 0, 20), 'blank' => $blank, 'over_limit' => $over, 'unlinked' => array_slice(array_keys($unlinked), 0, 10), 'link_hits' => $hits];
    }

    /**
     * Ghi thật. Chỉ trưởng nhóm duyệt bài trở lên (vì có thể tạo chiến dịch). Chiến dịch mới luôn ở trạng thái TẠM DỪNG, chưa gọi AI viết.
     *
     * @param  list<array<string,mixed>>  $items  kết quả build()['items']
     * @param  string  $target  by_column | new | solo | id chiến dịch của website
     * @return array{created:int, campaigns:list<string>, skipped:list<string>, notes:list<string>}
     */
    public function apply(User $actor, Website $site, array $items, string $target, ?string $newName = null, string $fileName = ''): array
    {
        if (! $actor->is_active || ! $actor->isApprover()) {
            throw new InvalidArgumentException('Chỉ trưởng nhóm duyệt bài trở lên mới nhập kế hoạch từ file.');
        }
        if ($site->status !== 'active') {
            throw new InvalidArgumentException('Website đích chưa ở trạng thái Hoạt động.');
        }
        if ($items === []) {
            throw new InvalidArgumentException('Không có dòng hợp lệ nào để tạo.');
        }
        $fixed = null;
        $newName = trim(PlanFileFields::text((string) $newName, 120));
        if ($target === self::TARGET_NEW) {
            if ($newName === '') {
                throw new InvalidArgumentException('Nhập tên chiến dịch mới.');
            }
        } elseif ($target !== self::TARGET_BY_COLUMN && $target !== self::TARGET_SOLO) {
            $fixed = ctype_digit($target) ? Campaign::query()->where('website_id', $site->id)->find((int) $target) : null;
            if (! $fixed) {
                throw new InvalidArgumentException('Chiến dịch đích không thuộc website này.');
            }
        }

        return DB::transaction(function () use ($actor, $site, $items, $target, $newName, $fixed, $fileName) {
            $creator = new PlannedArticleCreator;
            $stored = Keyword::query()->where('website_id', $site->id)->get()->keyBy('keyword_key');
            $dayTimes = PlanData::dayTimes(PlanData::articles($site));
            $today = VnTime::now()->startOfDay();
            $campaigns = [];
            $newCampaigns = [];
            $skipped = [];
            $notes = [];
            $created = 0;
            $dated = 0;
            $unsaved = 0;
            $volNew = 0;
            $volFilled = 0;
            $metaFilled = 0;
            $canDate = PlanData::canPersistDates();
            foreach ($items as $it) {
                $name = match ($target) {
                    self::TARGET_BY_COLUMN => $it['campaign'],
                    self::TARGET_NEW => $newName,
                    default => null,
                };
                $campaign = $fixed;
                if ($name !== null && $name !== '' && ! $fixed) {
                    $campaign = $campaigns[mb_strtolower($name)] ??= $this->campaign($actor, $site, $name, (string) ($it['campaign_description'] ?? ''), $fileName, $newCampaigns, $it['campaign_start'] ?? null, $it['campaign_end'] ?? null);
                }
                $kw = $stored->get(Keyword::keyFor($it['keyword']));
                // lượng tìm kiếm trong file: điền vào kho từ khóa (chỉ chỗ còn trống, không ghi đè số liệu từ nguồn khác); từ khóa chưa có trong kho thì thêm vào
                if (($it['volume'] ?? null) !== null || ($it['difficulty'] ?? null) !== null || ($it['cpc'] ?? null) !== null || ($it['competition'] ?? null) !== null) {
                    if ($kw === null && \App\Filament\Support\Keywords\KeywordSanitizer::check((string) $it['keyword'])['keyword'] === null) {
                        // từ khóa có thẻ/mã: không đưa vào kho từ khóa
                    } elseif ($kw === null) {
                        $kw = Keyword::create(['website_id' => $site->id, 'keyword' => $it['keyword'], 'keyword_key' => Keyword::keyFor($it['keyword']), 'volume' => $it['volume'] ?? null, 'volume_source' => ($it['volume'] ?? null) !== null ? 'csv' : null,
                            'difficulty' => $it['difficulty'] ?? null, 'cpc_vnd' => $it['cpc'] ?? null, 'competition' => $it['competition'] ?? null, 'intent' => $it['intent'], 'source' => 'csv', 'status' => 'new', 'created_by' => $actor->id]);
                        $stored->put($kw->keyword_key, $kw);
                        $volNew++;
                    } else {
                        $fill = [];
                        if (($it['volume'] ?? null) !== null && $kw->volume === null) {
                            $fill += ['volume' => $it['volume'], 'volume_source' => 'csv'];
                            $volFilled++;
                        }
                        if (($it['difficulty'] ?? null) !== null && $kw->difficulty === null) {
                            $fill['difficulty'] = $it['difficulty']; // độ khó trong file chỉ điền chỗ còn trống, không ghi đè số liệu từ nguồn khác
                        }
                        if (($it['cpc'] ?? null) !== null && $kw->cpc_vnd === null) {
                            $fill['cpc_vnd'] = $it['cpc'];
                        }
                        if (($it['competition'] ?? null) !== null && $kw->competition === null) {
                            $fill['competition'] = $it['competition'];
                        }
                        if ($fill !== []) {
                            $kw->update($fill);
                            $metaFilled += count(array_intersect(array_keys($fill), ['difficulty', 'cpc_vnd', 'competition']));
                        }
                    }
                }
                try {
                    $a = $creator->create($actor, $site, $it['keyword'], $it['cluster'], $it['intent'], $it['secondary'], $kw, 'manual');
                } catch (DuplicateKeywordException) {
                    $skipped[] = "dòng {$it['row']} ({$it['keyword']}): đã có bài";

                    continue;
                }
                $a->update(array_filter([
                    'origin_ref' => $fileName !== '' ? 'file:'.PlanFileFields::text($fileName, 200) : null,
                    'campaign_id' => $campaign?->id,
                    'priority' => $it['priority'],
                    'guidance' => $it['guidance'],
                    'brief' => ($it['brief_json'] ?? []) !== [] ? $it['brief_json'] : null,
                ], fn ($v) => $v !== null));
                $created++;

                if ($it['date_invalid']) {
                    $notes[] = "dòng {$it['row']}: không hiểu ngày, bài tạo không có ngày";
                } elseif ($it['date'] && ! $canDate) {
                    $unsaved++;
                } elseif ($it['date']) {
                    $day = CarbonImmutable::parse($it['date'], VnTime::TZ);
                    $taken = $dayTimes[$day->format('Y-m-d')] ?? [];
                    if ($day->lt($today)) {
                        $notes[] = "dòng {$it['row']}: ngày {$day->format('d/m/Y')} đã qua, bài tạo không có ngày";
                    } elseif (PlanRules::dayFullReason(count($taken)) !== null) {
                        $notes[] = "dòng {$it['row']}: ngày {$day->format('d/m/Y')} đã đủ ".PlanRules::DAILY_LIMIT.' bài, bài tạo không có ngày';
                    } else {
                        $slot = PlanRules::firstFreeSlot($taken);
                        $dayTimes[$day->format('Y-m-d')][] = $slot;
                        PlanData::setPlannedDate($a->id, $day->format('Y-m-d').' '.$slot);
                        $dated++;
                    }
                }
            }
            if ($created === 0) {
                throw new InvalidArgumentException('Không tạo được bài nào: '.implode('; ', $skipped));
            }
            foreach ($campaigns as $c) {
                if (! in_array($c->name, $newCampaigns, true)) {
                    continue; // chiến dịch có sẵn giữ nguyên mục tiêu người dùng đã đặt
                }
                $c->update(['target_article_count' => Article::query()->where('campaign_id', $c->id)->count()]);
            }
            if ($unsaved > 0) {
                $notes[] = "$unsaved bài có ngày trong file nhưng CHƯA lưu được ngày dự kiến: hệ thống cần chạy migration thêm cột ngày (php artisan migrate). Bài vẫn được tạo, chưa có ngày.";
            }
            if ($volNew + $volFilled > 0) {
                $notes[] = "Lượng tìm kiếm đã lưu vào kho từ khóa: $volFilled từ khóa có sẵn được điền, $volNew từ khóa mới được thêm.";
            }
            if ($metaFilled > 0) {
                $notes[] = "Độ khó, CPC, mức cạnh tranh: đã điền $metaFilled số liệu vào kho từ khóa (chỉ chỗ còn trống).";
            }
            if ($dated > 0) {
                $notes[] = "$dated bài đã được xếp ngày dự kiến (giờ Việt Nam).";
            }

            return ['created' => $created, 'campaigns' => $newCampaigns, 'skipped' => $skipped, 'notes' => $notes];
        });
    }

    /** Dùng lại chiến dịch cùng tên của website (nhập lại file không sinh chiến dịch rỗng thừa); chưa có thì tạo, TẠM DỪNG. */
    private function campaign(User $actor, Website $site, string $name, string $description, string $fileName, array &$created, ?string $start = null, ?string $end = null): Campaign
    {
        $c = Campaign::query()->where('website_id', $site->id)->get()->first(fn (Campaign $x) => mb_strtolower(trim((string) $x->name)) === mb_strtolower($name));
        if ($c) {
            return $c;
        }
        $created[] = $name;

        return Campaign::create([
            'website_id' => $site->id, 'created_by' => $actor->id, 'name' => $name, 'status' => Campaign::STATUS_PAUSED, 'source' => 'plan', 'keywords' => [],
            'description' => $description !== '' ? $description : 'Tạo từ file kế hoạch'.($fileName !== '' ? ' "'.PlanFileFields::text($fileName, 80).'"' : '').'.',
            'start_date' => $start, 'end_date' => $end && (! $start || $end >= $start) ? $end : null,
        ]);
    }
}
