<?php

namespace App\Services\Publishing;

use App\Models\Website;

/**
 * AG-S04: connector có thể làm việc với bản NHÁP đã đẩy trước (đăng từ nháp thay vì tạo bài mới).
 * Chỉ connector nào thực sự bảo đảm không đăng trùng mới được implement; Magento hiện KHÔNG implement
 * (bridge chưa có xóa nháp / gắn khóa lên nháp có sẵn) nên đội AI không đẩy nháp cho Magento.
 * Việc đăng từ nháp nằm trong PublisherInterface::publish() của chính connector (nó đọc cms_draft_id của ArticleVersion).
 */
interface DraftCapable
{
    /** Đưa bài nháp vào thùng rác trên CMS (không xóa vĩnh viễn). Ném lỗi nếu bài không còn là nháp. */
    public function deleteDraft(Website $website, string $remoteId): void;
}
