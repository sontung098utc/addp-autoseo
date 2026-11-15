<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Keywords\Serp\GoogleAutocompleteProvider;
use App\Services\Keywords\Serp\SerpFailed;
use App\Services\Keywords\Trends\SuggestionHistory;
use Illuminate\Console\Command;

/**
 * Ghi lại danh sách gợi ý tự động của Google cho các chủ đề đang theo dõi (miễn phí, không cần khóa) để sau vài ngày so được cụm nào mới nổi.
 * Không tham số: dùng chủ đề (topic_cluster) của các chiến dịch. Nên lên lịch hằng ngày (lịch chạy thuộc Dev1).
 */
class TrendsSnapshot extends Command
{
    protected $signature = 'trends:snapshot {seed?* : Chủ đề cần ghi (bỏ trống = chủ đề các chiến dịch)} {--pause=1 : Giây nghỉ giữa hai lần gọi}';

    protected $description = 'Ghi lịch sử gợi ý tự động của Google cho các chủ đề đang theo dõi (xu hướng miễn phí)';

    public function handle(): int
    {
        $seeds = array_values(array_filter(array_map('trim', (array) $this->argument('seed'))));
        if ($seeds === []) {
            $seeds = Campaign::query()->whereNotNull('topic_cluster')->where('topic_cluster', '!=', '')->distinct()->pluck('topic_cluster')->all();
        }
        if ($seeds === []) {
            $this->warn('Không có chủ đề nào để ghi (chưa có chiến dịch có chủ đề, và không truyền chủ đề).');

            return self::SUCCESS;
        }
        $provider = new GoogleAutocompleteProvider;
        $history = new SuggestionHistory;
        $fail = 0;
        foreach ($seeds as $i => $seed) {
            if ($i > 0) {
                sleep(max(0, (int) $this->option('pause')));
            }
            try {
                $list = $provider->insights($seed)['autocomplete'];
                $history->record($seed, $list);
                $this->info("«{$seed}»: ghi ".count($list).' gợi ý.');
            } catch (SerpFailed $e) {
                $fail++;
                $this->error("«{$seed}»: ".$e->getMessage());
            }
        }

        return $fail === count($seeds) ? self::FAILURE : self::SUCCESS;
    }
}
