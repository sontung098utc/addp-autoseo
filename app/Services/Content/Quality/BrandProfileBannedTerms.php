<?php

namespace App\Services\Content\Quality;

use App\Services\Brand\BrandProfileData;

/** AG-S19: từ cấm và tên đối thủ lấy từ hồ sơ thương hiệu của website của bài. Không có hồ sơ thì trả mảng rỗng. */
final class BrandProfileBannedTerms implements BannedTermsProvider
{
    public function termsFor(QualityContext $ctx): array
    {
        $p = BrandProfileData::forWebsite($ctx->article?->website_id);
        if (! $p) {
            return [];
        }
        $out = [];
        if (($t = BrandProfileData::list($p->forbidden_terms)) !== []) {
            $out['dieu_cam'] = $t;
        }
        if (($t = BrandProfileData::list($p->avoid_competitors)) !== []) {
            $out['doi_thu'] = $t;
        }

        return $out;
    }
}
