<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Agent\Eval\BaselineRunner;
use App\Services\Agent\Eval\EvalJudgeAi;
use App\Services\Agent\Eval\GoldenQuerySet;
use App\Services\Agent\Settings\AgentAi;
use Illuminate\Console\Command;

/** Q0.3: đo baseline chất lượng bài của luồng hiện tại trên bộ từ khóa chuẩn. Không đổi hành vi hệ thống. */
class AgentEvalBaselineCommand extends Command
{
    protected $signature = 'agent:eval-baseline
        {--mode=existing : existing = chấm bài ĐÃ CÓ khớp từ khóa chuẩn; generate = tạo bài trong --website rồi chấm (TỐN CHI PHÍ, GHI DB)}
        {--set= : đường dẫn bộ từ khóa chuẩn (mặc định resources/eval/golden-queries.json)}
        {--website= : ID website (bắt buộc với generate; với existing là bộ lọc tùy chọn)}
        {--limit=0 : chỉ đo N từ khóa đầu tiên (0 = tất cả)}
        {--no-ai : chỉ chạy số đo tất định, không gọi giám khảo AI}
        {--yes : xác nhận chi phí khi chạy generate}
        {--out= : thư mục xuất báo cáo (mặc định storage/app/eval)}';

    protected $description = 'Đo baseline chất lượng bài (vệ sinh SEO, độ phủ ý định, khẳng định có nguồn) trên bộ từ khóa chuẩn.';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['existing', 'generate'], true)) {
            $this->error('--mode phải là existing hoặc generate.');

            return self::FAILURE;
        }
        try {
            $queries = GoldenQuerySet::load($this->option('set') ?: null);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ((int) $this->option('limit') > 0) {
            $queries = array_slice($queries, 0, (int) $this->option('limit'));
        }
        $judge = $this->option('no-ai') ? null : EvalJudgeAi::resolve();
        if (! $this->option('no-ai') && $judge === null) {
            $this->warn('Chưa có AI cho giám khảo: các số đo cần AI sẽ ghi "chưa chạy" (không phải 0).');
        }
        $runner = new BaselineRunner($judge);

        if ($mode === 'existing') {
            $report = $runner->existing($queries, $this->option('website') ? (int) $this->option('website') : null);
        } else {
            $site = $this->option('website') ? Website::find((int) $this->option('website')) : null;
            if (! $site) {
                $this->error('Chế độ generate cần --website=ID hợp lệ.');

                return self::FAILURE;
            }
            if (! $this->option('yes')) {
                $this->error('Chế độ generate gọi AI thật cho '.count($queries).' từ khóa, tốn chi phí và ghi vào DB. Thêm --yes để xác nhận.');

                return self::FAILURE;
            }
            $ai = AgentAi::real();
            if ($ai === null) {
                $this->error('Chưa kết nối AI thật cho đội AI (không dùng bản giả lập để đo baseline).');

                return self::FAILURE;
            }
            $report = $runner->generate($queries, $site, $ai, AgentAi::policy(), function (int $i, int $n, $q, array $row) {
                $this->line("[{$i}/{$n}] {$q->keyword}: ".$row['status'].(isset($row['run']['status']) ? ' (phiên '.$row['run']['status'].')' : ''));
            });
        }

        $dir = rtrim((string) ($this->option('out') ?: storage_path('app/eval')), '/');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Không tạo được thư mục {$dir}.");

            return self::FAILURE;
        }
        $stamp = now()->format('Ymd-His');
        file_put_contents("{$dir}/baseline-{$stamp}.json", json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents("{$dir}/baseline-{$stamp}.md", BaselineRunner::markdown($report));
        $all = $report['summary']['all'] ?? null;
        $this->info($all ? "Đã đo {$all['n']} bài. Vệ sinh SEO TB: {$all['hygiene']}." : 'Không đo được bài nào.');
        $this->line("Báo cáo: {$dir}/baseline-{$stamp}.md (+ .json)");

        return self::SUCCESS;
    }
}
