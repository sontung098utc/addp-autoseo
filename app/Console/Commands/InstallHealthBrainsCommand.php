<?php

namespace App\Console\Commands;

use App\Services\Brain\BrainService;
use App\Services\Brain\BrainTemplates;
use Illuminate\Console\Command;

/** AG-B2: cài các Trí tuệ sức khỏe từ mẫu (chỉ khung, không kiến thức). Idempotent: đã có thì bỏ qua, không ghi đè. */
class InstallHealthBrainsCommand extends Command
{
    protected $signature = 'brains:install-health {--only=* : Chỉ cài các khóa mẫu này (mặc định: 14 mẫu sức khỏe)} {--website= : Gắn luôn các Trí tuệ mới tạo với website này (ID)}';

    protected $description = 'Cài các Trí tuệ sức khỏe mẫu (khung cấu trúc, chưa có kiến thức nào được duyệt)';

    public function handle(): int
    {
        $keys = $this->option('only') ?: null;
        $site = $this->option('website') ? \App\Models\Website::query()->find((int) $this->option('website')) : null;
        if ($this->option('website') && ! $site) {
            $this->error('Không tìm thấy website '.$this->option('website').'.');

            return self::FAILURE;
        }
        $r = (new BrainService())->installTemplates($keys, $site);
        foreach ($r['created'] as $b) {
            $this->line('Đã tạo: '.$b->name);
        }
        foreach ($r['skipped'] as $k) {
            $this->line('Bỏ qua (đã có): '.(BrainTemplates::get($k)['name'] ?? $k));
        }
        foreach ($r['unknown'] as $k) {
            $this->warn('Không có mẫu: '.$k);
        }
        $this->info(count($r['created']).' mới, '.count($r['skipped']).' bỏ qua. Mẫu: chưa có kiến thức nào được duyệt.');

        return $r['unknown'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
