<?php

namespace App\Console\Commands;

use App\Models\ArticleVersion;
use App\Services\Agent\Eval\ArticleMetrics;
use App\Services\Agent\Eval\EvalJudgeAi;
use App\Services\Agent\Eval\GoldenQuerySet;
use App\Services\Agent\Eval\ReviewSheetExporter;
use Illuminate\Console\Command;

/** Q0.4: xuất phiếu chấm HTML cho người (dược sĩ) từ một báo cáo baseline hoặc từ danh sách phiên bản bài. */
class AgentEvalSheetCommand extends Command
{
    protected $signature = 'agent:eval-sheet
        {--report= : tệp baseline-*.json (lấy các bài đã đo và nhãn AI trong đó)}
        {--versions= : danh sách ID phiên bản bài, cách nhau bằng dấu phẩy (khi không có --report)}
        {--only= : chỉ lấy hạng rủi ro này (low|medium|high)}
        {--distinct : mỗi từ khóa chuẩn chỉ lấy một bản (mới nhất)}
        {--limit=0 : tối đa N bài}
        {--title=Phiếu chấm chất lượng bài : tiêu đề phiếu}
        {--out= : đường dẫn tệp HTML (mặc định storage/app/eval/sheet-<thời gian>.html)}';

    protected $description = 'Xuất phiếu chấm chất lượng (HTML tự chứa) cho người duyệt.';

    public function handle(): int
    {
        $queries = GoldenQuerySet::load();
        $entries = [];
        if ($report = $this->option('report')) {
            $data = is_file($report) ? json_decode((string) file_get_contents($report), true) : null;
            if (! is_array($data) || ! isset($data['rows'])) {
                $this->error('Không đọc được báo cáo baseline.');

                return self::FAILURE;
            }
            $rows = array_values(array_filter($data['rows'], fn ($r) => ($r['status'] ?? '') === 'measured' && (! $this->option('only') || $r['risk'] === $this->option('only'))));
            if ($this->option('distinct')) {
                $latest = [];
                foreach ($rows as $r) { // bản mới nhất = id phiên bản lớn nhất của từ khóa chuẩn đó
                    $g = (string) $r['golden_id'];
                    if (! isset($latest[$g]) || $r['metrics']['version_id'] > $latest[$g]['metrics']['version_id']) {
                        $latest[$g] = $r;
                    }
                }
                $rows = array_values($latest);
            }
            foreach ($rows as $row) {
                $v = ArticleVersion::find($row['metrics']['version_id']);
                if ($v) {
                    $entries[] = ['version' => $v, 'metrics' => $row['metrics'], 'query' => $this->query($queries, $row['golden_id'])];
                }
            }
        } else {
            $ids = array_filter(array_map('intval', explode(',', (string) $this->option('versions'))));
            if ($ids === []) {
                $this->error('Cần --report hoặc --versions.');

                return self::FAILURE;
            }
            $metrics = new ArticleMetrics(EvalJudgeAi::resolve());
            foreach (ArticleVersion::query()->whereIn('id', $ids)->get() as $v) {
                $q = GoldenQuerySet::findByKeyword($queries, (string) $v->article?->primary_keyword);
                $entries[] = ['version' => $v, 'metrics' => $metrics->measure($v, $v->article, $q), 'query' => $q];
            }
        }
        if ((int) $this->option('limit') > 0) {
            $entries = array_slice($entries, 0, (int) $this->option('limit'));
        }
        if ($entries === []) {
            $this->error('Không có bài nào để xuất phiếu.');

            return self::FAILURE;
        }
        $path = $this->option('out') ?: storage_path('app/eval/sheet-'.now()->format('Ymd-His').'.html');
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
            $this->error('Không tạo được thư mục xuất.');

            return self::FAILURE;
        }
        file_put_contents($path, (new ReviewSheetExporter)->html($entries, (string) $this->option('title')));
        $this->info(count($entries).' bài trong phiếu: '.$path);

        return self::SUCCESS;
    }

    /** @param list<\App\Services\Agent\Eval\GoldenQuery> $queries */
    private function query(array $queries, ?string $id): ?\App\Services\Agent\Eval\GoldenQuery
    {
        foreach ($queries as $q) {
            if ($q->id === $id) {
                return $q;
            }
        }

        return null;
    }
}
