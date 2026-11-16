<?php

namespace App\Services\Agent\Readiness;

use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Brain;
use App\Services\Agent\Risk\RiskClassifier;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Brain\BrainContext;
use App\Services\Brand\BrandProfileData;
use App\Services\Knowledge\Build\BrainBuildService;
use App\Services\Knowledge\KnowledgeRetriever;

/**
 * "Sẵn sàng trước khi giao": trả lời, TRƯỚC khi tốn token, bài này sẽ viết dựa trên những gì (kho kiến thức, sản phẩm, hồ sơ doanh nghiệp, Trí tuệ)
 * và còn thiếu gì. Thuần luật, 0 token, không gọi mạng. Dùng chung cho giao diện (khối "Sẵn sàng trước khi giao", hộp thoại Giao việc)
 * và cổng dừng trước bước viết của đội AI.
 *
 * Kết quả (mảng có khóa cố định):
 *  - risk: ['level' => low|medium|high, 'reasons' => list<string>] (từ khóa + sàn rủi ro của các Trí tuệ đã gắn)
 *  - brains: ['attached' => list<array{id,name}>, 'suggested' => list<array{id,name,reason}>]  (gợi ý: Trí tuệ đang hoạt động CHƯA gắn website nhưng khớp chủ đề)
 *  - knowledge: ['count' => int, 'items' => list<array{id,title}>] mục kiến thức ĐÃ DUYỆT khớp chủ đề (tối đa 5, cùng cách truy xuất khi viết)
 *  - products: ['count' => int, 'items' => list<array{id,title}>]
 *  - brand: ['percent' => int, 'missing' => list<string>]
 *  - cost: ['estimate_vnd' => ?float (trung bình các phiên viết gần nhất của website, null nếu chưa có), 'basis' => string, 'budget_vnd' => float]
 *  - issues: list<array{code,severity,message}> severity: 'warn' | 'info' (không còn mức chặn)
 *  - holds_writing: bool, DEPRECATED: luôn false (không còn cổng dừng; AI tự chuẩn bị kiến thức, giữ khóa để nơi cũ không vỡ)
 *  - needs_expert: bool (rủi ro cao VÀ kho chưa có mục đã duyệt khớp: viết xong sẽ gắn nhãn cần chuyên gia xem lại)
 *  - auto_actions: list<string> điều AI SẼ tự làm khi giao (cùng điều kiện với AgentRunOrchestrator)
 */
final class ArticleReadiness
{
    /** @return array<string, mixed> */
    public static function for(Article $article, ?int $campaignId = null, ?KnowledgeRetriever $retriever = null): array
    {
        $retriever ??= new KnowledgeRetriever;
        $site = $article->website;
        $keyword = trim((string) $article->primary_keyword);
        $ctx = BrainContext::forArticle($article, $campaignId);

        $assess = (new RiskClassifier)->assess($keyword);
        $level = $ctx->raise($assess->level);
        $reasons = $assess->reasons;
        if ($level !== $assess->level) {
            $reasons[] = 'Nâng hạng theo cấu hình của Trí tuệ: '.$ctx->names().'.';
        }

        $knowledge = ['count' => 0, 'items' => []];
        $products = ['count' => 0, 'items' => []];
        if ($site && $keyword !== '') {
            try {
                $ids = $ctx->ids() ?: null;
                $knowledge['items'] = array_map(fn ($r) => ['id' => $r['id'], 'title' => $r['title']], $retriever->search($site, $keyword, 5, $ids));
                $products['items'] = array_map(fn ($r) => ['id' => $r['id'], 'title' => $r['title']], $retriever->products($site, $keyword, 2, $ids));
            } catch (\Throwable) {
                // kho lỗi thì coi như chưa có: không làm hỏng trang
            }
        }
        $knowledge['count'] = count($knowledge['items']);
        $products['count'] = count($products['items']);

        $profile = BrandProfileData::completeness($site ? BrandProfileData::forWebsite($site->id) : null);
        $suggested = $site ? BrainSuggester::forArticle($article, $ctx->ids()) : [];
        $cost = self::cost($article);

        $issues = [];
        $high = $level === 'high';
        if ($knowledge['count'] === 0) {
            $issues[] = ['code' => 'kb_empty', 'severity' => 'warn',
                'message' => $high
                    ? 'Chủ đề rủi ro cao mà kho chưa có mục kiến thức đã duyệt nào khớp. AI sẽ tự bổ sung kiến thức nếu làm được; bài viết xong sẽ được gắn nhãn cần chuyên gia xem lại.'
                    : 'Kho chưa có mục kiến thức đã duyệt khớp chủ đề này; AI viết từ kiến thức chung của model.'];
        }
        if ($ctx->isEmpty()) {
            $issues[] = ['code' => 'no_brain', 'severity' => $suggested !== [] ? 'warn' : 'info',
                'message' => $suggested !== []
                    ? 'Website chưa gắn Trí tuệ nào, nhưng có Trí tuệ khớp chủ đề: '.implode(', ', array_column($suggested, 'name')).'.'
                    : 'Website chưa gắn Trí tuệ nào.'];
        }
        if ($profile['missing'] !== []) {
            $issues[] = ['code' => 'brand_incomplete', 'severity' => $profile['percent'] === 0 ? 'warn' : 'info',
                'message' => 'Hồ sơ doanh nghiệp '.($profile['percent'] === 0 ? 'chưa có' : 'chưa đủ ('.$profile['percent'].'%)').': AI thiếu giọng thương hiệu, thông điệp, từ cấm.'];
        }
        if ($site && $keyword !== '' && BrandProfileData::looksOffTopic(BrandProfileData::forWebsite($site->id), $keyword)) {
            $issues[] = ['code' => 'off_topic', 'severity' => 'warn', 'message' => 'Chủ đề «'.mb_substr($keyword, 0, 80).'» có vẻ ngoài lĩnh vực của website (theo hồ sơ doanh nghiệp). Kiểm tra lại trước khi giao, hoặc xóa khỏi kế hoạch.'];
        }
        if ($products['count'] === 0) {
            $issues[] = ['code' => 'no_product', 'severity' => 'info', 'message' => 'Chưa có sản phẩm nào của công ty khớp chủ đề này trong kho.'];
        }

        $needsExpert = $high && $knowledge['count'] === 0;

        return [
            'risk' => ['level' => $level, 'reasons' => $reasons],
            'brains' => ['attached' => array_map(fn (Brain $b) => ['id' => $b->id, 'name' => $b->name], $ctx->brains), 'suggested' => $suggested],
            'knowledge' => $knowledge,
            'products' => $products,
            'brand' => ['percent' => $profile['percent'], 'missing' => $profile['missing']],
            'cost' => $cost,
            'issues' => $issues,
            'holds_writing' => false,
            'needs_expert' => $needsExpert,
            'auto_actions' => self::autoActions($article, $ctx, $needsExpert),
        ];
    }

    /** @return list<string> */
    private static function autoActions(Article $article, BrainContext $ctx, bool $needsExpert): array
    {
        $out = [];
        try {
            $site = $article->website;
            $new = $site ? BrainAutoAttach::candidates($article, $ctx->ids()) : [];
            foreach ($new as $b) {
                $out[] = 'Tự gắn Trí tuệ «'.$b['name'].'» ('.lcfirst($b['reason']).')';
            }
            if ($needsExpert) {
                if ($site && trim((string) $site->base_url) !== '' && (new BrainBuildService)->availability()['ok']) {
                    $out[] = 'Tự đọc tối đa '.AgentRunOrchestrator::GAP_PAGES.' trang trên website công ty để bổ sung kiến thức';
                }
                $out[] = 'Viết xong sẽ gắn nhãn cần chuyên gia xem lại (chủ đề rủi ro cao)';
            }
        } catch (\Throwable) {
            // chỉ là lời giải thích: lỗi thì bỏ qua
        }

        return $out;
    }

    /** @return array{estimate_vnd: ?float, basis: string, budget_vnd: float} */
    private static function cost(Article $article): array
    {
        $budget = (float) AgentAi::policy()->budgetVnd;
        $avg = AgentRun::query()->where('website_id', $article->website_id)->where('job_type', 'article_write')
            ->where('cost_vnd', '>', 0)->where('ai_mode', 'real')->orderByDesc('id')->limit(10)->pluck('cost_vnd');
        if ($avg->isEmpty()) {
            return ['estimate_vnd' => null, 'basis' => 'Chưa có phiên viết thật nào của website này để ước lượng.', 'budget_vnd' => $budget];
        }

        return ['estimate_vnd' => round((float) $avg->avg(), 0), 'basis' => 'Trung bình '.$avg->count().' phiên viết thật gần nhất của website (chưa tính vòng sửa nếu có).', 'budget_vnd' => $budget];
    }
}
