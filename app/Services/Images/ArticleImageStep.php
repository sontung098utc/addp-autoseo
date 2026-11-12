<?php

namespace App\Services\Images;

use App\Filament\Support\Schedule\ErrorMasker;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;

/**
 * Bước 'images' của đội AI. README cho phiên chính nối vào AgentRunOrchestrator (V1 sở hữu file đó):
 *
 *   // sau khi lưu phiên bản + attachFeatured(), trước handoff:
 *   $img = app(\App\Services\Images\ArticleImageStep::class)->run($run, $article, $version, ++$this->seq, $this->policy->budgetVnd);
 *   $totalCost += $img['cost_vnd'];   // bước đã ghi cost_vnd vào agent_run_steps; run->cost_vnd do orchestrator cộng
 *
 * - Ghi ĐÚNG MỘT agent_run_steps: stage 'images', role 'system', status ok | warn | skipped. Không bao giờ ném lỗi ra ngoài.
 * - $seq: truyền số thứ tự orchestrator đang giữ (bảng có unique(agent_run_id, seq)); bỏ trống thì lấy max+1.
 * - data: {version_id, generated, skipped_reason, skip_code, items, cost, errors, generator}.
 * - Tự bỏ qua có lý do: chưa có nhà cung cấp ảnh; website đã có ảnh bìa từ thư viện; sức khỏe (config agent.images.medical=false);
 *   hết trần chi phí (10% ngân sách phiên); đã có ảnh AI cho phiên bản này; tính năng tắt.
 * - HTML bản nháp có thể được sửa (chèn <figure> sau H2): gọi sau khi đã lưu phiên bản và chấm điểm xong nếu muốn chấm trên bản không ảnh.
 */
final class ArticleImageStep
{
    public function __construct(private ?ArticleImageService $service = null) {}

    /** @return array{status:string, generated:int, cost_vnd:float, skipped:?string, items:list<array{asset_id:int,role:string,prompt:string}>} */
    public function run(AgentRun $run, Article $article, ArticleVersion $version, ?int $seq = null, ?float $budgetVnd = null, array $options = []): array
    {
        try {
            $r = ($this->service ?? new ArticleImageService)->generateFor($article, $version, $options + ($budgetVnd !== null ? ['budget_vnd' => $budgetVnd] : []));
        } catch (\Throwable $e) {
            $r = ['generated' => 0, 'skipped' => null, 'cost_vnd' => 0.0, 'items' => [], 'errors' => [ErrorMasker::mask($e->getMessage())], 'skip_code' => 'exception'];
        }
        $errors = $r['errors'] ?? [];
        $gen = (int) $r['generated'];
        $status = $errors !== [] || ($r['skip_code'] ?? '') === 'cost_cap' && $gen > 0 ? 'warn' : ($gen > 0 ? 'ok' : 'skipped');
        if (($r['skip_code'] ?? '') === 'cost_cap' && $gen === 0) {
            $status = 'warn';
        }
        $title = $gen > 0 ? "Đã tạo {$gen} ảnh minh họa (AI tạo)" : ($errors !== [] ? 'Tạo ảnh lỗi, bài vẫn giữ nguyên' : 'Không tạo ảnh');
        $detail = $gen > 0
            ? "Chi phí ảnh: {$r['cost_vnd']}đ. Ảnh gắn vào phiên bản, nhãn \"AI tạo\" ghi ở thư viện media."
            : ($r['skipped'] ?? 'Không có ảnh nào được tạo.');
        if ($gen > 0 && ($r['stopped'] ?? '') === 'cost_cap') {
            $detail .= ' Dừng sớm vì chạm trần chi phí ảnh.';
        }
        if ($gen > 0 && $errors !== []) {
            $detail .= ' Một ảnh lỗi nên dừng: '.$errors[0];
        }
        AgentRunStep::create([
            'agent_run_id' => $run->id, 'seq' => $seq ?? ((int) AgentRunStep::query()->where('agent_run_id', $run->id)->max('seq') + 1),
            'stage' => 'images', 'role' => 'designer', 'status' => $status, 'title' => $title, 'detail' => $detail,
            'data' => ['version_id' => $version->id, 'generated' => $gen, 'skipped_reason' => $r['skipped'], 'skip_code' => $r['skip_code'] ?? null,
                'items' => $r['items'], 'cost' => $r['cost_vnd'], 'errors' => $errors, 'generator' => $r['generator_label'] ?? null, 'role_key' => 'designer'],
            'model' => $r['items'] !== [] ? 'image' : null, 'tokens_used' => 0, 'cost_vnd' => $r['cost_vnd'], 'started_at' => now(), 'finished_at' => now(),
        ]);

        return ['status' => $status, 'generated' => $gen, 'cost_vnd' => (float) $r['cost_vnd'], 'skipped' => $r['skipped'], 'items' => $r['items']];
    }
}
