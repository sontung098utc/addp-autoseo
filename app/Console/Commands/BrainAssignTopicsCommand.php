<?php

namespace App\Console\Commands;

use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Services\Knowledge\KnowledgeAiIngest;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeTopicAssigner;
use Illuminate\Console\Command;

class BrainAssignTopicsCommand extends Command
{
    protected $signature = 'brain:assign-topics {brain : ID Trí tuệ} {--overwrite : Gán lại cả mục đã có chủ đề} {--report : Chỉ in độ phủ theo chủ đề, không gọi AI}';

    protected $description = 'Gán mục kiến thức của một Trí tuệ vào chủ đề của Trí tuệ đó (AI chỉ được chọn chủ đề có sẵn) và in độ phủ; không đổi trạng thái duyệt';

    public function handle(): int
    {
        $brain = Brain::query()->find($this->argument('brain'));
        if (! $brain) {
            $this->error('Không thấy Trí tuệ.');

            return self::FAILURE;
        }
        if (! $this->option('report')) {
            try {
                $ai = (new KnowledgeAiIngest)->resolve()['ai'];
            } catch (KnowledgeError $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $items = KnowledgeItem::query()->where('brain_id', $brain->id)->whereNotIn('status', ['rejected', 'outdated'])->orderBy('id')->get();
            $r = (new KnowledgeTopicAssigner($ai))->assign($brain, $items, (bool) $this->option('overwrite'));
            $this->info("Đã gán {$r['assigned']} mục, không có chủ đề phù hợp {$r['unassigned']}, bỏ qua (đã có chủ đề) {$r['skipped']}, phản hồi sai {$r['invalid']}, thử lại {$r['retries']} lần, chi phí ".number_format($r['cost_vnd'], 0, ',', '.').' ₫.');
            if ($r['stopped']) {
                $this->warn('Dừng vì: '.$r['stopped'].($r['error'] ? ' — '.$r['error'] : ''));
            }
        }
        $this->table(['Chủ đề', 'Số mục', 'Đã duyệt'], array_map(fn ($t) => [$t['path'], $t['items'], $t['approved']], KnowledgeTopicAssigner::coverage($brain)));

        return self::SUCCESS;
    }
}
