<?php

namespace App\Filament\Support\Agent;

use App\Filament\Pages\BrainBuildPage;
use App\Filament\Pages\KeywordsPage;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Models\AgentRun;
use App\Services\Agent\Pipeline\JobTypeRegistry;

/**
 * Báo cáo kết quả của MỘT phiên chạy, theo loại việc, bằng lời đời thường + liên kết tới bản ghi (khảo sát → trang Từ khóa, xây kho → trang Xây kho,
 * viết bài → bài và phiên bản). Chỉ dùng dữ liệu đã ghi (output_ref, steps); thiếu thì nói "không ghi nhận", không bịa.
 */
final class RunReport
{
    /** @return array{title:string, lines:list<string>, links:list<array{label:string,url:string}>} */
    public static function for(AgentRun $run): array
    {
        $type = (string) ($run->job_type ?: 'article_write');
        $title = 'Báo cáo: '.(JobTypeRegistry::find($type)['title'] ?? $type);
        $out = (array) $run->output_ref;
        $steps = $run->relationLoaded('steps') ? $run->steps : $run->steps()->get();
        $lines = [];
        $links = [];

        $cost = number_format((float) $run->cost_vnd, 0, ',', '.').' ₫';
        $dur = $run->started_at && $run->finished_at ? max(0, $run->finished_at->diffInSeconds($run->started_at)).' giây' : null;

        if ($type === 'market_research') {
            $added = $out['added'] ?? (isset($out['keyword_ids']) ? count((array) $out['keyword_ids']) : null);
            $lines[] = $added === null ? 'Không ghi nhận số từ khóa đã thêm.' : "Đã thêm {$added} từ khóa vào kho (nguồn: AI đề xuất, lượt tìm để trống nếu chưa có nhà cung cấp số liệu).";
            if (isset($out['proposals'])) {
                $lines[] = 'Đề xuất gom cụm: '.(int) $out['proposals'].' cụm chờ bạn nhận.';
            }
            if (class_exists(KeywordsPage::class)) {
                $links[] = ['label' => 'Mở trang Từ khóa', 'url' => KeywordsPage::getUrl()];
            }
        } elseif ($type === 'brain_build') {
            $lines[] = 'Đã nạp '.(int) ($out['auto_approved'] ?? 0).' mục tự nhận và '.(int) ($out['proposed'] ?? 0).' mục chờ duyệt'
                .(isset($out['source_ids']) ? ' từ '.count((array) $out['source_ids']).' nguồn.' : '.');
            if (class_exists(BrainBuildPage::class)) {
                $links[] = ['label' => 'Mở trang Xây kho', 'url' => BrainBuildPage::getUrl()];
            }
        } else {
            $score = $steps->where('stage', 'grade')->sortByDesc('id')->first()?->data['score'] ?? null;
            $lines[] = $run->final_version_id
                ? 'Đã tạo bản nháp'.($score !== null ? " (điểm hình thức {$score}/100)" : '').'.'
                : 'Chưa có bản nháp nào từ phiên này.';
            $research = $steps->where('stage', 'research')->sortByDesc('id')->first();
            if ($research) {
                $k = (int) ($research->data['knowledge']['count'] ?? 0);
                $lines[] = "Kiến thức đã đưa cho AI: {$k} mục; nguồn được phép trích: ".count((array) ($research->data['citable_sources'] ?? [])).'.';
            }
            $img = $steps->where('stage', 'images')->sortByDesc('id')->first();
            if ($img) {
                $lines[] = 'Ảnh: '.($img->status === 'skipped' ? 'không tạo ('.($img->data['skipped_reason'] ?? $img->detail ?? 'đã bỏ qua').')' : (int) ($img->data['generated'] ?? 0).' ảnh AI tạo.');
            }
            if ($run->article_id) {
                $links[] = ['label' => 'Mở bài viết', 'url' => ArticleResource::getUrl('view', ['record' => $run->article_id])];
            }
            if ($run->final_version_id) {
                $links[] = ['label' => 'Xem phiên bản đã tạo', 'url' => ArticleVersionResource::getUrl('view', ['record' => $run->final_version_id])];
            }
        }
        $lines[] = 'Chi phí AI: '.$cost.($dur ? ", thời gian {$dur}" : '').'; '.($run->status === 'failed' ? 'phiên lỗi' : 'trạng thái: '.mb_strtolower(AgentRunResource::STATUS[$run->status][0] ?? (string) $run->status)).'.';
        if ($run->outcome_reason) {
            $lines[] = 'Lý do kết thúc: '.$run->outcome_reason;
        }

        return ['title' => $title, 'lines' => $lines, 'links' => $links];
    }
}
