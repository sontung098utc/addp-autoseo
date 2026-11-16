<?php

namespace App\Console\Commands;

use App\Services\Agent\Eval\JudgeCalibration;
use App\Services\Agent\Eval\ReviewSheetExporter;
use Illuminate\Console\Command;

/** Q0.5: nhập tệp nhãn do người chấm (tải từ phiếu HTML), lưu lại và đo độ chính xác của giám khảo AI so với người. */
class AgentEvalLabelsCommand extends Command
{
    protected $signature = 'agent:eval-labels {file : tệp nhan-cham-*.json tải từ phiếu} {--out= : thư mục lưu (mặc định storage/app/eval/labels)}';

    protected $description = 'Nhập nhãn của người chấm và đo giám khảo AI (độ chính xác, kappa, precision/recall).';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($data) || ($data['schema'] ?? '') !== ReviewSheetExporter::SCHEMA || ! is_array($data['labels'] ?? null)) {
            $this->error('Tệp không phải nhãn hợp lệ (schema '.ReviewSheetExporter::SCHEMA.').');

            return self::FAILURE;
        }
        $dir = rtrim((string) ($this->option('out') ?: storage_path('app/eval/labels')), '/');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Không tạo được thư mục {$dir}.");

            return self::FAILURE;
        }
        $dest = $dir.'/'.date('Ymd-His').'-'.basename($file);
        copy($file, $dest);

        $verdicts = array_count_values(array_filter(array_map(fn ($l) => (string) ($l['verdict'] ?? ''), $data['labels'])));
        $this->info('Đã lưu '.count($data['labels'])." bài do «{$data['reviewer']}» chấm: {$dest}");
        $this->line('Nhận định chung: '.($verdicts === [] ? 'chưa có' : json_encode($verdicts, JSON_UNESCAPED_UNICODE)));

        $cal = JudgeCalibration::fromLabels($data);
        foreach (['claims' => 'Giám khảo kiểm trích dẫn (câu ↔ nguồn)', 'intent' => 'Giám khảo độ phủ ý định'] as $k => $label) {
            $c = $cal[$k];
            if ($c['n'] === 0) {
                $this->line("{$label}: chưa có cặp nào được người chấm chọn.");

                continue;
            }
            $this->line("{$label}: n={$c['n']}, chính xác ".number_format($c['accuracy'] * 100, 1).'%, kappa '.($c['kappa'] ?? 'không xác định'));
            foreach ($c['per_class'] as $cls => $p) {
                $this->line("   - {$cls}: precision ".($p['precision'] ?? '-').', recall '.($p['recall'] ?? '-').", số mẫu người {$p['support']}");
            }
        }
        file_put_contents(preg_replace('/\.json$/', '', $dest).'.calibration.json', json_encode($cal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
