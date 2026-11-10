<?php

namespace App\Services\Content\Quality;

use App\Models\Competitor;
use App\Services\Brand\BrandProfileData;

/**
 * AG-S19: từ cấm và tên đối thủ lấy từ hồ sơ thương hiệu của website của bài. Không có hồ sơ thì trả mảng rỗng.
 * AG-S24: đối thủ loại "trực tiếp" của website cũng tự thành từ cấm nhóm doi_thu (tên thương hiệu + tên miền bỏ đuôi).
 */
final class BrandProfileBannedTerms implements BannedTermsProvider
{
    public function termsFor(QualityContext $ctx): array
    {
        $websiteId = $ctx->article?->website_id;
        $p = BrandProfileData::forWebsite($websiteId);
        $out = [];
        if ($p) {
            if (($t = BrandProfileData::list($p->forbidden_terms)) !== []) {
                $out['dieu_cam'] = $t;
            }
            if (($t = BrandProfileData::list($p->avoid_competitors)) !== []) {
                $out['doi_thu'] = $t;
            }
        }
        if ($websiteId) {
            $names = [];
            foreach (Competitor::where('website_id', $websiteId)->where('kind', 'truc_tiep')->get(['domain', 'brand_name']) as $c) {
                foreach ([$c->brand_name, $c->domainLabel()] as $n) {
                    $n = BrandProfileData::text($n ?? '', BrandProfileData::MAX_ITEM);
                    // Tên quá ngắn (<3 ký tự) dễ chặn nhầm từ thường; bỏ qua.
                    if (mb_strlen($n) >= 3) {
                        $names[] = $n;
                    }
                }
            }
            if ($names !== []) {
                $out['doi_thu'] = array_values(array_unique(array_merge($out['doi_thu'] ?? [], $names)));
            }
        }

        return $out;
    }
}
