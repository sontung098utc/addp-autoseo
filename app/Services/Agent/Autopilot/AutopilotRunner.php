<?php

namespace App\Services\Agent\Autopilot;

use App\Models\Article;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Keywords\KeywordAiResolver;
use App\Services\Keywords\KeywordAiUnavailable;
use App\Services\Keywords\KeywordResearchService;
use App\Services\Planning\ContentPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Autopilot: nối khảo sát thị trường → lập kế hoạch → hàng chờ viết cho chiến dịch chế độ "Toàn quyền" (mode 'autonomous', nguồn 'plan').
 * Chạy TRƯỚC khi CampaignRunner chọn bài kế tiếp. Chỉ làm khi hàng chờ bài "đã lên kế hoạch" chưa có phiên đang vơi xuống dưới ngưỡng:
 *  1) kho từ khóa mới (status 'new') cạn thì khảo sát AI một lần mỗi 24 giờ mỗi website (AI chỉ đề xuất; lượt tìm lấy thật nếu có nhà cung cấp, không thì để trống);
 *  2) lập kế hoạch bằng luật (ContentPlanner), thêm tối đa plan_batch bài, bỏ chủ đề lệch hồ sơ doanh nghiệp.
 * Trong suốt: trả về 'actions' (câu đời thường) để hiện ở kết quả nhịp; người dùng can thiệp bằng cách sửa/xóa bài đã lên kế hoạch hoặc chuyển chiến dịch về "Duyệt trước".
 * Chưa chạy nền thật (đồng bộ theo nhịp lịch); chưa gom cụm AI tự động (để người xem cụm ở trang Từ khóa).
 */
final class AutopilotRunner
{
    public function __construct(private ?ContentPlanner $planner = null, private ?\Closure $researchFactory = null) {}

    /** @return array{result:string, message:string, actions:list<string>} */
    public function tick(Campaign $c, ?CarbonImmutable $now = null): array
    {
        $none = ['result' => 'skipped', 'message' => '', 'actions' => []];
        $site = $c->website;
        if ($c->mode !== 'autonomous' || $c->status !== 'active' || $c->source !== 'plan' || ! $site || $site->status !== 'active') {
            return $none;
        }
        $min = max(1, (int) config('agent.autopilot.queue_min', 3));
        $batch = max(1, (int) config('agent.autopilot.plan_batch', 3));

        $queue = Article::query()->where('website_id', $site->id)->where('workflow_status', 'planned')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('agent_runs')->whereColumn('agent_runs.article_id', 'articles.id'))->count();
        if ($queue >= $min) {
            return ['result' => 'queue_ok', 'message' => "Hàng chờ viết còn {$queue} bài, chưa cần bổ sung.", 'actions' => []];
        }

        $actions = [];
        $fresh = Keyword::query()->where('website_id', $site->id)->where('status', 'new')->count();
        if ($fresh < $min && Cache::add($this->researchKey($site->id), 1, now()->addDay())) {
            try {
                if (! $this->researchFactory) {
                    // Autopilot không dùng AI giả lập (môi trường local) cho khảo sát: nó không có kịch bản và chỉ tạo phiên lỗi.
                    $ai = KeywordAiResolver::availability('keyword_suggest');
                    if (! $ai['ok'] || $ai['demo']) {
                        throw new KeywordAiUnavailable($ai['reason'] ?? 'AI giả lập không dùng cho Autopilot.');
                    }
                }
                $service = $this->researchFactory ? ($this->researchFactory)() : new KeywordResearchService;
                $r = $service->suggest($site);
                $actions[] = 'Đã khảo sát thị trường: thêm '.(int) ($r['added'] ?? 0).' từ khóa mới'.(isset($r['metrics']['updated']) && $r['metrics']['updated'] > 0 ? ' (có số lượt tìm thật cho '.$r['metrics']['updated'].')' : ' (chưa có số lượt tìm thật)').'.';
            } catch (KeywordAiUnavailable) {
                Cache::forget($this->researchKey($site->id)); // chưa có AI: thử lại nhịp sau, không chặn việc lập kế hoạch
            } catch (\Throwable $e) {
                Log::warning('Autopilot: khảo sát lỗi, bỏ qua', ['website' => $site->id, 'error' => class_basename($e)]);
            }
        }

        $planner = $this->planner ?? new ContentPlanner;
        $proposals = array_values(array_filter($planner->propose($site, $batch * 3), fn ($p) => ! $p['off_topic']));
        $ids = array_slice(array_column($proposals, 'keyword_id'), 0, $batch);
        if ($ids !== []) {
            $res = $planner->create(CampaignRunner::systemUser(), $site, $ids);
            if (($res['created'] ?? 0) > 0) {
                $titles = array_slice(array_column(array_filter($proposals, fn ($p) => in_array($p['keyword_id'], $ids, true)), 'keyword'), 0, 3);
                $actions[] = 'Đã thêm '.$res['created'].' bài vào kế hoạch: '.implode(', ', $titles).'.';
                Article::query()->where('website_id', $site->id)->where('workflow_status', 'planned')->whereNull('campaign_id')->where('origin', 'plan')
                    ->where('created_by', CampaignRunner::systemUser()->id)->update(['campaign_id' => $c->id]);
            }
        }
        if ($actions !== []) {
            Log::info('Autopilot', ['campaign' => $c->id, 'website' => $site->id, 'actions' => $actions]);
        }

        return ['result' => $actions === [] ? 'nothing' : 'acted', 'message' => $actions === [] ? 'Chưa có từ khóa phù hợp để thêm vào kế hoạch.' : implode(' ', $actions), 'actions' => $actions];
    }

    private function researchKey(int $websiteId): string
    {
        return 'autopilot.research.'.$websiteId;
    }
}
