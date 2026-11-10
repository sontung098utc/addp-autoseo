<?php

namespace App\Services\Content\Quality;

use App\Models\KnowledgeItem;
use App\Services\Brain\BrainContext;
use App\Services\Knowledge\KnowledgeRetriever;

/**
 * AG-B3 (P2, Tầng 2): cụm từ cấm lấy từ các mục kiến thức loại 'Không được nói' ĐÃ DUYỆT, còn hiệu lực, của các Trí tuệ mà bài đang dùng
 * (Trí tuệ của website, cộng Trí tuệ do chiến dịch chọn nếu có). Đi qua luật BAN01 (P0) nên chặn bằng luật, không chỉ dặn trong prompt.
 * Mỗi mục là một nhóm riêng "không được nói KB#id" để người duyệt biết mục nào đã chặn. Mục không có cụm từ chỉ có tác dụng ở mức prompt.
 */
final class KnowledgeForbiddenTerms implements BannedTermsProvider
{
    public function termsFor(QualityContext $ctx): array
    {
        $article = $ctx->article;
        $site = $article?->website;
        if (! $article || ! $site) {
            return [];
        }
        $ids = array_values(array_unique(array_merge(BrainContext::forArticle($article)->ids(), array_map('intval', $ctx->brainIds ?? []))));
        // Luôn lọc theo danh sách Trí tuệ (kể cả rỗng): null sẽ nạp mục của MỌI Trí tuệ khác.
        $out = [];
        foreach ((new KnowledgeRetriever())->eligible($site, $ids)->where('type', 'forbidden')->limit(200)->get() as $item) {
            /** @var KnowledgeItem $item */
            if (($phrases = KnowledgeRetriever::phrases($item)) !== []) {
                $out['không được nói KB#'.$item->id] = $phrases;
            }
        }

        return $out;
    }
}
