<?php

namespace App\Services\Onboarding;

use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Readiness\BrainAutoAttach;
use App\Services\Brand\BrandProfileData;
use App\Services\Keywords\KeywordResearchService;
use App\Services\Knowledge\Build\BrainBuildService;
use App\Services\Planning\ContentPlanner;

/**
 * "Khởi tạo website" một nút: thay cho việc tự đi 6 trang. Tự làm theo thứ tự, mỗi bước độc lập (một bước lỗi không dừng các bước sau), kết quả nói rõ đã làm gì:
 *  1) đọc website công ty → kho kiến thức + hồ sơ doanh nghiệp tự điền (BrainBuildService; cần AI)
 *  2) tự gắn Trí tuệ khớp lĩnh vực của doanh nghiệp (BrainAutoAttach)
 *  3) khảo sát từ khóa quanh hồ sơ doanh nghiệp (KeywordResearchService; cần AI; lượt tìm thật nếu có nhà cung cấp)
 *  4) xem trước kế hoạch nên viết (ContentPlanner::propose: chỉ TÍNH, không tạo bài; người hoặc Autopilot quyết định)
 * Chạy đồng bộ (chưa có chạy nền). Không tạo bài, không giao đội AI viết.
 */
final class WebsiteOnboarding
{
    public function __construct(private ?\Closure $buildFactory = null, private ?\Closure $researchFactory = null) {}

    /** @return array{steps: list<array{key:string,label:string,status:'ok'|'skipped'|'failed',message:string}>} */
    public function run(Website $site, ?User $by = null): array
    {
        $steps = [];
        $add = function (string $key, string $label, string $status, string $message) use (&$steps) {
            $steps[] = compact('key', 'label', 'status', 'message');
        };

        // 1) Kho + hồ sơ
        try {
            $build = $this->buildFactory ? ($this->buildFactory)() : new BrainBuildService;
            $r = $build->run($site, $by, ['max_pages' => 15]);
            if ((int) ($r['pages_read'] ?? 0) === 0) {
                $add('kb', 'Đọc website và xây kho', 'skipped', 'Chưa đọc được trang nào, nên kho chưa có gì mới'.(! empty($r['stopped_reason']) ? ': '.$r['stopped_reason'] : ' (website không truy cập được từ máy chủ này, hoặc chưa có AI)').'. Thử lại ở trang Xây kho từ website.');
            } else {
                $add('kb', 'Đọc website và xây kho', 'ok',
                    'Đã đọc '.(int) $r['pages_read'].' trang; '.(int) ($r['items_auto'] ?? 0).' mục tự nhận, '.(int) ($r['items_proposed'] ?? 0).' mục chờ duyệt'
                    .(! empty($r['brand_profile']['applied']) ? '; đã tự điền hồ sơ doanh nghiệp ('.count((array) ($r['brand_profile']['fields'] ?? [])).' trường)' : '').'.'
                    .(! empty($r['stopped_reason']) ? ' '.$r['stopped_reason'] : ''));
            }
        } catch (\Throwable $e) {
            $add('kb', 'Đọc website và xây kho', 'skipped', $this->why($e, 'Chưa xây được kho (cần AI và website truy cập được).'));
        }

        // 2) Trí tuệ theo lĩnh vực
        try {
            $field = trim((string) BrandProfileData::forWebsite($site->id)?->business_field);
            if ($field === '') {
                $add('brains', 'Gắn Trí tuệ theo lĩnh vực', 'skipped', 'Chưa biết lĩnh vực của doanh nghiệp (hồ sơ trống), nên chưa gắn Trí tuệ nào. Trí tuệ vẫn tự gắn khi giao từng bài.');
            } else {
                $probe = new Article(['website_id' => $site->id, 'primary_keyword' => mb_substr($field, 0, 120)]);
                $attached = BrainAutoAttach::ensureFor($probe, $by);
                $add('brains', 'Gắn Trí tuệ theo lĩnh vực', 'ok', $attached === [] ? 'Không có Trí tuệ nào khớp lĩnh vực «'.mb_substr($field, 0, 60).'» (hoặc đã gắn rồi).' : 'Đã tự gắn: '.implode(', ', array_column($attached, 'name')).'.');
            }
        } catch (\Throwable $e) {
            $add('brains', 'Gắn Trí tuệ theo lĩnh vực', 'failed', $this->why($e, 'Không gắn được Trí tuệ.'));
        }

        // 3) Khảo sát từ khóa
        try {
            $research = $this->researchFactory ? ($this->researchFactory)() : new KeywordResearchService;
            $r = $research->suggest($site);
            if ((int) ($r['added'] ?? 0) === 0) {
                $add('keywords', 'Khảo sát từ khóa', 'skipped', 'Chưa thêm được từ khóa nào'.(! empty($r['message']) ? ': '.$r['message'] : ' (chưa có AI thật hoặc AI không đề xuất được từ mới)').'.');
            } else {
                $add('keywords', 'Khảo sát từ khóa', 'ok', 'Đã thêm '.(int) ($r['added'] ?? 0).' từ khóa mới'.(((int) ($r['metrics']['updated'] ?? 0)) > 0 ? ' (có lượt tìm thật cho '.$r['metrics']['updated'].')' : ' (chưa có lượt tìm thật: nhập CSV hoặc cấu hình DataForSEO)').'.');
            }
        } catch (\Throwable $e) {
            $add('keywords', 'Khảo sát từ khóa', 'skipped', $this->why($e, 'Chưa khảo sát được (cần AI).'));
        }

        // 4) Xem trước kế hoạch
        try {
            $props = (new ContentPlanner)->propose($site, 5);
            $add('plan', 'Gợi ý kế hoạch', 'ok', $props === [] ? 'Chưa có từ khóa nào để gợi ý. Nhập CSV hoặc chạy khảo sát khi có AI.' : 'Có '.count($props).' bài nên viết trước: '.implode(', ', array_slice(array_column($props, 'keyword'), 0, 3)).'. Bạn duyệt ở trang Kế hoạch nội dung.');
        } catch (\Throwable $e) {
            $add('plan', 'Gợi ý kế hoạch', 'failed', $this->why($e, 'Không tính được gợi ý kế hoạch.'));
        }

        return ['steps' => $steps];
    }

    private function why(\Throwable $e, string $fallback): string
    {
        $m = trim($e->getMessage());

        return $m !== '' && mb_strlen($m) < 240 ? $m : $fallback;
    }
}
