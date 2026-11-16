<?php

namespace App\Services\Content\Quality;

/** Nguồn danh sách từ cấm. Mặc định theo website, chiến dịch ghi đè (AG-K13); cài đặt thật nối ở AG-K11. */
interface BannedTermsProvider
{
    /**
     * @return array<string, list<string>> nhãn nhóm => cụm cấm
     */
    public function termsFor(QualityContext $ctx): array;
}
