<?php

namespace App\Services\Agent\Pipeline;

use App\Models\AiModelRoute;
use App\Services\Agent\Roles\RoleRegistry;

/**
 * G1: sổ "loại việc" của đội AI, xếp theo chuỗi làm ra một bài (THIET_KE_PIPELINE_THEO_LOAI_VIEC.md §3, §5.1).
 * Mỗi loại việc là MỘT FILE trong resources/agents/job-types/<mã>.php (cùng cách FeatureRegistry làm với Features/*.php).
 * Registry chỉ KHAI BÁO; không chạy gì. Quy tắc cứng (có test): mỗi loại việc phải khai `writes`, `shown_at`, `used_by`.
 *
 * Trường của một mục: id, title, link (khóa mắt xích trong CHAIN), order, status (active|planned: planned = chưa có mã chạy),
 * reads[], writes[], shown_at[], used_by[] (mã loại việc khác hoặc 'human'), steps[] {id (<=20 ký tự), label, kind (rule|llm),
 * task? (khóa AiModelRoute::TASKS khi kind=llm), when?, planned? (true = bước chưa chạy trong mã hiện tại)},
 * (mỗi bước có thêm `role`: khóa vai trò trong RoleRegistry hoặc 'system'), records[] {label, class, type (input|output|report), filter_job_type?},
 * budget?, human[] (điểm người can thiệp), features[] (id trong FeatureRegistry), runner? (FQCN), summary.
 */
final class JobTypeRegistry
{
    /** Chuỗi làm ra một bài: khóa => nhãn. 'measure' nằm sau xuất bản và nối ngược về kế hoạch. */
    public const CHAIN = [
        'keyword' => 'Từ khóa',
        'plan' => 'Kế hoạch',
        'research' => 'Nghiên cứu và nguồn',
        'write' => 'Viết',
        'review' => 'Kiểm và duyệt',
        'publish' => 'Xuất bản',
        'measure' => 'Đo lường',
    ];

    /** Loại việc có thể khai hai mắt xích cho cùng một mục (article_write phục vụ 3, 4, 5): khóa chính là `link`, phụ trong `also`. */
    public const STATUSES = ['active', 'planned'];

    /** @return list<array<string, mixed>> theo `order` */
    public static function all(): array
    {
        $rows = [];
        foreach (glob(base_path('resources/agents/job-types/*.php')) ?: [] as $file) {
            $row = require $file;
            $rows[] = (array) $row + [
                'status' => 'planned', 'also' => [], 'reads' => [], 'writes' => [], 'shown_at' => [], 'used_by' => [],
                'steps' => [], 'budget' => null, 'human' => [], 'features' => [], 'runner' => null, 'summary' => '', 'order' => 99, 'records' => [],
            ];
        }
        usort($rows, fn (array $a, array $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** Các loại việc phục vụ một mắt xích (khóa chính hoặc phụ). @return list<array<string, mixed>> */
    public static function forLink(string $link): array
    {
        return array_values(array_filter(self::all(), fn (array $r) => $r['link'] === $link || in_array($link, (array) $r['also'], true)));
    }

    /**
     * Nhãn các chặng của một loại việc, theo thứ tự: mã chặng => nhãn. Mặc định bỏ bước `planned` (chưa chạy trong mã),
     * để trang bài và trang phiên chỉ vẽ chặng có thật.
     *
     * @return array<string, string>
     */
    public static function stageLabels(string $jobType = 'article_write', bool $includePlanned = false): array
    {
        $out = [];
        foreach ((self::find($jobType)['steps'] ?? []) as $s) {
            if ($includePlanned || empty($s['planned'])) {
                $out[$s['id']] = $s['label'];
            }
        }

        return $out;
    }

    /** Khóa vai trò hợp lệ cho `role` của bước: 'system' và mọi vai trò trong RoleRegistry. @return list<string> */
    public static function roleKeys(): array
    {
        return ['system', ...array_column((new RoleRegistry)->all(), 'key')];
    }

    /**
     * Vi phạm luật khai báo. Rỗng là hợp lệ. Test nhất quán gọi hàm này.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $p = [];
        $ids = [];
        $all = self::all();
        $known = array_column($all, 'id');
        foreach ($all as $r) {
            $id = (string) ($r['id'] ?? '?');
            $ids[$id] = ($ids[$id] ?? 0) + 1;
            if (! isset(self::CHAIN[$r['link'] ?? ''])) {
                $p[] = "$id: link '".($r['link'] ?? '')."' không thuộc chuỗi";
            }
            if (! in_array($r['status'], self::STATUSES, true)) {
                $p[] = "$id: status không hợp lệ";
            }
            foreach (['writes', 'shown_at', 'used_by'] as $k) {
                if (empty($r[$k])) {
                    $p[] = "$id: thiếu `$k` (loại việc phải ghi vào đâu, hiện ở đâu, ai dùng tiếp)";
                }
            }
            foreach ((array) $r['used_by'] as $u) {
                if ($u !== 'human' && ! in_array($u, $known, true)) {
                    $p[] = "$id: used_by '$u' không phải loại việc nào";
                }
            }
            foreach ((array) $r['also'] as $l) {
                if (! isset(self::CHAIN[$l])) {
                    $p[] = "$id: also '$l' không thuộc chuỗi";
                }
            }
            foreach ((array) $r['steps'] as $s) {
                $sid = (string) ($s['id'] ?? '');
                if ($sid === '' || strlen($sid) > 20) {
                    $p[] = "$id: mã bước '$sid' rỗng hoặc dài quá 20 ký tự";
                }
                if (! in_array($s['role'] ?? '', self::roleKeys(), true)) {
                    $p[] = "$id/$sid: thiếu `role` hợp lệ (system hoặc khóa vai trò)";
                }
                if (! in_array($s['kind'] ?? '', ['rule', 'llm'], true)) {
                    $p[] = "$id/$sid: kind phải là rule hoặc llm";
                }
                if (($s['kind'] ?? '') === 'llm' && empty($s['planned']) && ! isset(AiModelRoute::TASKS[$s['task'] ?? ''])) {
                    $p[] = "$id/$sid: bước llm đang chạy cần `task` thuộc AiModelRoute::TASKS (bước `planned` được đặt tên task tương lai)";
                }
            }
        }
        foreach ($ids as $id => $n) {
            if ($n > 1) {
                $p[] = "$id: khai trùng";
            }
        }

        return $p;
    }
}
