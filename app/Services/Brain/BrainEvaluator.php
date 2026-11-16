<?php

namespace App\Services\Brain;

use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeRetriever;
use Illuminate\Support\Facades\DB;

/**
 * Đo ĐỘ PHỦ TRUY XUẤT của một Trí tuệ bằng bộ câu hỏi vàng (resources/brains/evals/{domain_key}.php). Không gọi AI, tất định.
 * Với mỗi câu hỏi: lấy top-$k mục kho mà người viết sẽ nhận (đúng bộ truy xuất thật), rồi xem các mục đó có phủ đủ "ý bắt buộc" không
 * (mỗi ý là một nhóm cách nói tương đương; phủ khi bất kỳ mục nào chứa một cách nói trong nhóm).
 * $includeProposed: tính cả mục CHỜ DUYỆT để đo tiềm năng của kho; chạy trong giao dịch rồi hoàn lại, không ghi gì vào CSDL.
 * Bộ câu hỏi do AI soạn thì kết quả chỉ là chỉ báo, chưa phải đánh giá chuyên môn: chuyên gia phải gán nhãn bộ câu hỏi.
 */
final class BrainEvaluator
{
    public function __construct(private ?KnowledgeRetriever $retriever = null)
    {
        $this->retriever ??= new KnowledgeRetriever;
    }

    /** @return array<string,mixed>|null */
    public static function spec(Brain $brain): ?array
    {
        $stored = (array) (((array) $brain->settings)['eval'] ?? []); // bộ câu hỏi do quy trình Xây kho chuyên ngành soạn, lưu theo Trí tuệ
        if (! empty($stored['questions'])) {
            return ['status' => (string) ($stored['status'] ?? 'ai_draft'), 'questions' => (array) $stored['questions']];
        }
        $f = resource_path('brains/evals/'.$brain->domain_key.'.php');

        return is_file($f) ? (array) include $f : null;
    }

    /**
     * @return array{status:string, total:int, passed:int, partial:int, failed:int, mode:string, rows:list<array<string,mixed>>}|null null khi Trí tuệ chưa có bộ câu hỏi
     */
    public function run(Brain $brain, Website $site, bool $includeProposed = false, int $k = 8, ?array $spec = null): ?array
    {
        $spec ??= self::spec($brain);
        if ($spec === null) {
            return null;
        }
        $go = fn () => $this->evaluate($brain, $site, (array) ($spec['questions'] ?? []), $k);
        if ($includeProposed) {
            DB::beginTransaction();
            try {
                KnowledgeItem::query()->where('brain_id', $brain->id)->where('status', 'proposed')->update(['status' => 'approved']);
                $rows = $go();
            } finally {
                DB::rollBack();
            }
        } else {
            $rows = $go();
        }
        $passed = count(array_filter($rows, fn ($r) => $r['coverage'] >= 1.0));
        $failed = count(array_filter($rows, fn ($r) => $r['coverage'] <= 0.0));

        return ['status' => (string) ($spec['status'] ?? ''), 'total' => count($rows), 'passed' => $passed, 'partial' => count($rows) - $passed - $failed, 'failed' => $failed,
            'mode' => $includeProposed ? 'cả mục chờ duyệt (tiềm năng)' : 'chỉ mục đã duyệt (dùng được ngay)', 'rows' => $rows];
    }

    /**
     * Cụm cần tìm có trong văn bản không: khớp nguyên cụm, hoặc (cụm từ 2 từ trở lên) có ít nhất 75% các từ nội dung (≥3 ký tự) của cụm trong văn bản.
     * Bộ câu hỏi do AI soạn thường dùng cụm dài ("tác động của tập thể dục"); khớp nguyên cụm sẽ đánh trượt mục vẫn nói đúng ý.
     */
    private static function phraseHit(string $text, string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }
        if (str_contains($text, $phrase)) {
            return true;
        }
        $words = array_values(array_filter(explode(' ', $phrase), fn ($w) => mb_strlen($w) >= 3));
        if (count($words) < 2) {
            return false;
        }
        $have = count(array_filter($words, fn ($w) => str_contains($text, $w)));

        return $have / count($words) >= 0.75;
    }

    /** @return list<array<string,mixed>> */
    private function evaluate(Brain $brain, Website $site, array $questions, int $k): array
    {
        $rows = [];
        foreach ($questions as $q) {
            $found = $this->retriever->search($site, (string) $q['q'], $k, [$brain->id]);
            $text = KnowledgeDedupe::normalize(implode(' ', array_map(fn ($r) => $r['title'].' '.$r['body'], $found)));
            $groups = (array) ($q['must'] ?? []);
            $miss = [];
            foreach ($groups as $alts) {
                $hit = false;
                foreach ((array) $alts as $a) {
                    $hit = $hit || self::phraseHit($text, KnowledgeDedupe::normalize((string) $a));
                }
                if (! $hit) {
                    $miss[] = (string) (((array) $alts)[0] ?? '');
                }
            }
            $rows[] = ['id' => (string) $q['id'], 'topic' => (string) ($q['topic'] ?? ''), 'q' => (string) $q['q'], 'found' => count($found), 'missing' => $miss,
                'coverage' => $groups === [] ? 1.0 : round((count($groups) - count($miss)) / count($groups), 2)];
        }

        return $rows;
    }
}
