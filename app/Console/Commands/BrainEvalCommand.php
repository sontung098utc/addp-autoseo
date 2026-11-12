<?php

namespace App\Console\Commands;

use App\Models\Brain;
use App\Services\Brain\BrainEvaluator;
use Illuminate\Console\Command;

class BrainEvalCommand extends Command
{
    protected $signature = 'brain:eval {brain : ID Trí tuệ} {--include-proposed : Tính cả mục chờ duyệt để đo tiềm năng (không ghi CSDL)} {--k=8 : Số mục lấy cho mỗi câu hỏi}';

    protected $description = 'Đo độ phủ truy xuất của Trí tuệ bằng bộ câu hỏi vàng (không gọi AI, không ghi CSDL)';

    public function handle(): int
    {
        $brain = Brain::query()->find($this->argument('brain'));
        $site = $brain?->websites()->first();
        if (! $brain || ! $site) {
            $this->error('Không thấy Trí tuệ hoặc Trí tuệ chưa gắn website nào.');

            return self::FAILURE;
        }
        $r = (new BrainEvaluator)->run($brain, $site, (bool) $this->option('include-proposed'), (int) $this->option('k'));
        if ($r === null) {
            $this->warn('Trí tuệ này chưa có bộ câu hỏi vàng (resources/brains/evals/'.$brain->domain_key.'.php).');

            return self::FAILURE;
        }
        $this->info("Chế độ: {$r['mode']}. Bộ câu hỏi: {$r['status']} (chờ chuyên gia gán nhãn nếu là ai_draft).");
        $this->table(['Mã', 'Chủ đề', 'Phủ', 'Số mục', 'Thiếu ý', 'Câu hỏi'], array_map(fn ($x) => [$x['id'], $x['topic'], (int) ($x['coverage'] * 100).'%', $x['found'], implode(', ', $x['missing']), mb_substr($x['q'], 0, 60)], $r['rows']));
        $this->info("Phủ đủ {$r['passed']}/{$r['total']}, phủ một phần {$r['partial']}, không phủ {$r['failed']}.");

        return self::SUCCESS;
    }
}
