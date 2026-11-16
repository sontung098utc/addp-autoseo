<?php

namespace App\Services\Agent\Handoff;

use App\Contracts\CmsErrorInterface;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Models\AgentRun;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Publishing\DraftCapable;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Publishing\PublisherManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AG-S04 (chế độ "Cân bằng"): sau khi bản của đội AI qua mọi luật P0, tự đẩy NHÁP (xem trước, không công khai) lên CMS.
 *
 * Chỉ đẩy khi: (a) phiên thuộc chiến dịch mode=balanced, (b) đạt P0, (c) rủi ro != high, (d) không dừng khẩn cấp,
 * (e) website active và connector hỗ trợ đăng từ nháp. KHÔNG bao giờ retry mù: lỗi mạng/timeout => cms_draft_status=failed,
 * ghi lỗi đã che; không làm hỏng phiên. Đăng chính thức từ nháp do connector xử lý trong publish() để không tạo bài thứ hai.
 */
class CmsDraftHandoff
{
    public function __construct(private PublisherManager $publishers, private EmergencyStopService $stop) {}

    public function push(AgentRun $run, ArticleVersion $version, ?Website $site, bool $passedP0, string $riskLevel): CmsDraftResult
    {
        // (a) Chỉ chiến dịch "Cân bằng". Phiên thủ công (không có chiến dịch) tuyệt đối không đẩy.
        $campaign = $run->campaign_id ? Campaign::query()->find($run->campaign_id) : null;
        if ($campaign === null || $campaign->mode !== 'balanced') {
            return CmsDraftResult::skipped();
        }
        if (! $passedP0) {
            return CmsDraftResult::skipped();
        }
        if ($riskLevel === 'high') {
            return CmsDraftResult::skipped('Chủ đề rủi ro cao nên không tự đẩy nháp lên CMS; chờ người có chuyên môn duyệt.');
        }
        if ($this->stop->isStopped($version->article?->website_id)) {
            return CmsDraftResult::skipped('Đang dừng khẩn cấp nên không đẩy nháp lên CMS.');
        }
        if (! $site || $site->status !== 'active') {
            return CmsDraftResult::skipped('Website không hoạt động nên không đẩy nháp lên CMS.');
        }
        if ($version->cms_draft_status !== null) {
            return CmsDraftResult::skipped(); // đã đẩy/đã thử: không đẩy lần hai
        }

        try {
            $publisher = $this->publishers->getPublisher($site);
        } catch (Throwable $e) {
            return CmsDraftResult::skipped('Chưa có connector cho CMS của website nên không đẩy nháp.');
        }
        $label = $this->label($site);
        if (! $publisher instanceof DraftCapable) {
            return CmsDraftResult::skipped("Connector {$label} chưa hỗ trợ đăng từ nháp (sẽ tạo bài trùng khi đăng) nên không đẩy nháp; bài vẫn chờ người duyệt.");
        }

        try {
            // KHÔNG truyền publication_key vào createDraft: connector WordPress dùng khóa đó làm dấu nhận dạng, khi đăng chính thức bước 1 sẽ thấy bản nháp
            // và chỉ đổi trạng thái (bỏ qua SEO meta/taxonomy, không ghi promoted_draft_id). Nháp của hệ thống tự nhận biết bằng cms_draft_id.
            $res = $publisher->createDraft($site, $version);
            $id = (string) ($res['remote_id'] ?? '');
            if ($id === '') {
                throw new \RuntimeException('CMS không trả mã bài nháp');
            }
            $url = (string) ($res['preview_url'] ?? '');
            $version->forceFill([
                'cms_draft_id' => $id, 'cms_draft_url' => $url !== '' ? mb_substr($url, 0, 500) : null,
                'cms_draft_pushed_at' => now(), 'cms_draft_status' => 'pushed',
            ])->save();

            return new CmsDraftResult('pushed', null, $label, $url !== '' ? $url : null, $id);
        } catch (CmsErrorInterface $e) {
            if (! $e->isAmbiguous()) {
                // Lỗi chuẩn hóa KHÔNG mơ hồ (vd 401/403/422, thiếu quyền): CMS chắc chắn chưa tạo nháp, nên cho phép thử lại.
                $msg = ErrorMasker::short($e->getMessage(), 160);
                Log::warning("[CmsDraftHandoff] Đẩy nháp version #{$version->id} lên {$label} thất bại chắc chắn [{$e->errorCode()}]: {$msg}");
                $version->forceFill(['cms_draft_status' => 'failed'])->save();

                return new CmsDraftResult('failed', "Không đẩy được nháp lên {$label} ({$msg}). Lỗi rõ ràng, CMS chưa tạo nháp nào; sau khi xử lý lỗi có thể đẩy lại. Bài vẫn chờ người duyệt.", $label);
            }

            return $this->draftFailedMaybeCreated($version, $label, $e);
        } catch (Throwable $e) {
            return $this->draftFailedMaybeCreated($version, $label, $e);
        }
    }

    /** Lỗi mơ hồ hoặc không rõ: nháp có thể đã tồn tại trên CMS, nên KHÔNG thử lại. */
    private function draftFailedMaybeCreated(ArticleVersion $version, string $label, Throwable $e): CmsDraftResult
    {
        // KHÔNG thử lại: nếu lỗi sau khi gửi thì nháp có thể đã tồn tại trên CMS (không có khóa nhận dạng để tra).
        $msg = ErrorMasker::short($e->getMessage(), 160);
        Log::warning("[CmsDraftHandoff] Đẩy nháp version #{$version->id} lên {$label} lỗi (không thử lại): {$msg}");
        $version->forceFill(['cms_draft_status' => 'failed'])->save();

        return new CmsDraftResult('failed', "Không đẩy được nháp lên {$label} ({$msg}). Không tự thử lại để tránh tạo trùng; nháp có thể đã tồn tại trên CMS, hãy kiểm tra thủ công. Bài vẫn chờ người duyệt.", $label);
    }

    /**
     * Hủy nháp trên CMS (thùng rác). Trả thông báo thành công; lỗi ném RuntimeException với thông điệp đã che.
     */
    public function cancel(ArticleVersion $version): string
    {
        if ($version->cms_draft_status !== 'pushed' || ! $version->cms_draft_id) {
            throw new \RuntimeException('Bản này không có nháp đang hiệu lực trên CMS.');
        }
        // Đã có lượt đăng chính thức (kể cả đang chạy/cần đối soát): nháp có thể đã thành bài công khai, không được xóa.
        if (Publication::query()->where('article_version_id', $version->id)->whereIn('status', ['publishing', 'published', 'needs_reconcile', 'held', 'paused', 'awaiting_confirm'])->exists()) {
            throw new \RuntimeException('Bản này đã có lượt đăng chính thức nên không hủy nháp được.');
        }
        $site = $version->article?->website;
        if (! $site) {
            throw new \RuntimeException('Không tìm thấy website của bài.');
        }
        try {
            $publisher = $this->publishers->getPublisher($site);
        } catch (Throwable) {
            throw new \RuntimeException('Chưa có connector cho CMS của website.');
        }
        if (! $publisher instanceof DraftCapable) {
            throw new \RuntimeException('Connector '.$this->label($site).' chưa hỗ trợ hủy nháp.');
        }
        try {
            $publisher->deleteDraft($site, (string) $version->cms_draft_id);
        } catch (Throwable $e) {
            $msg = ErrorMasker::short($e->getMessage(), 160);
            Log::warning("[CmsDraftHandoff] Hủy nháp version #{$version->id} lỗi: {$msg}");
            throw new \RuntimeException("Không hủy được nháp trên CMS: {$msg}");
        }
        $version->forceFill(['cms_draft_status' => 'cancelled'])->save();

        return 'Đã đưa nháp vào thùng rác trên '.$this->label($site).'.';
    }

    public static function statusLabel(?string $status): string
    {
        return ['pushed' => 'đã đẩy, chờ duyệt', 'failed' => 'đẩy lỗi', 'cancelled' => 'đã hủy', 'promoted' => 'đã đăng chính thức'][$status] ?? (string) $status;
    }

    private function label(Website $site): string
    {
        return ['wordpress' => 'WordPress', 'magento' => 'Magento', 'magento2' => 'Magento'][strtolower((string) $site->cms_type)] ?? 'CMS';
    }
}
