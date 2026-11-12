<?php

namespace App\Console\Commands;

use App\Models\AgentRunStep;
use App\Services\Images\ImageJob;
use Illuminate\Console\Command;

/** Tiến trình con của bước tạo ảnh song song với bước viết (xem ImageJob). Không dùng tay. */
class AgentRunImagesCommand extends Command
{
    protected $signature = 'agent:run-images {step : ID bước images của phiên chạy}';

    protected $description = 'Tạo ảnh theo kế hoạch ảnh của một phiên chạy (nội bộ: chạy song song với bước viết)';

    protected $hidden = true;

    public function handle(): int
    {
        $step = AgentRunStep::query()->where('stage', 'images')->find($this->argument('step'));
        if (! $step) {
            $this->error('Không thấy bước ảnh.');

            return self::FAILURE;
        }
        ImageJob::execute($step);

        return self::SUCCESS;
    }
}
