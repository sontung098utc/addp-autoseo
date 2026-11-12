<?php

namespace App\Console\Commands;

use App\Models\Brain;
use App\Models\User;
use App\Services\Brain\Build\DomainBrainBuilder;
use App\Services\Knowledge\KnowledgeAiIngest;
use App\Services\Knowledge\KnowledgeError;
use Illuminate\Console\Command;

class BrainBuildDomainCommand extends Command
{
    protected $signature = 'brain:build-domain {brain : ID Trí tuệ chuyên ngành}
                            {--review : Chỉ rà soát: kiểm lại, gán chủ đề, đo độ phủ, bổ sung chỗ thiếu (không lập kế hoạch nguồn mới trước)}
                            {--rounds=2 : Số vòng bổ sung chỗ thiếu tối đa}
                            {--max-sources=8 : Số nguồn tối đa mỗi vòng}
                            {--user= : ID người duyệt thực hiện (mặc định: quản trị viên đầu tiên đang hoạt động)}';

    protected $description = 'Xây kho chuyên ngành: AI lập kế hoạch nguồn theo bậc bằng chứng, nạp và rút mục, kiểm lại nghĩa, gán chủ đề, đo độ phủ, bổ sung chỗ thiếu, soạn hồ sơ nháp; chuyên gia duyệt';

    public function handle(): int
    {
        $brain = Brain::query()->find($this->argument('brain'));
        if (! $brain) {
            $this->error('Không thấy Trí tuệ.');

            return self::FAILURE;
        }
        $by = $this->option('user') ? User::find((int) $this->option('user')) : User::query()->where('is_active', true)->whereIn('role', ['admin', 'approver'])->orderBy('id')->first();
        if (! $by) {
            $this->error('Không có người duyệt nào để ghi nhận. Dùng --user=ID.');

            return self::FAILURE;
        }
        try {
            $r = (new KnowledgeAiIngest)->resolve();
            if ($r['demo']) {
                $this->warn('Đang dùng AI giả lập (môi trường local): kết quả chỉ để kiểm luồng.');
            }
            $out = (new DomainBrainBuilder($r['ai'], $r['label']))->run($brain, $by, ['review_only' => (bool) $this->option('review'), 'rounds' => (int) $this->option('rounds'), 'max_sources' => (int) $this->option('max-sources')]);
        } catch (KnowledgeError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (isset($out['error'])) {
            $this->error('Dừng: '.$out['error']);

            return self::FAILURE;
        }
        $m = $out['metrics'] ?? [];
        $this->info('Phiên #'.$out['run_id'].': '.($m['items'] ?? 0).' mục, độ phủ tiềm năng '.(isset($m['coverage_potential']) ? (int) ($m['coverage_potential'] * 100).'%' : 'chưa đo').', dùng được ngay '.(isset($m['coverage_usable']) ? (int) ($m['coverage_usable'] * 100).'%' : 'chưa đo').', chi phí '.number_format($out['cost_vnd'], 0, ',', '.').' ₫.');
        foreach ($out['todo_for_experts'] ?? [] as $t) {
            $this->line(' - '.$t);
        }

        return self::SUCCESS;
    }
}
