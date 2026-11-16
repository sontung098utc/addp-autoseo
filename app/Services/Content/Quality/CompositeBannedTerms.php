<?php

namespace App\Services\Content\Quality;

/** AG-S19: gộp nhiều nguồn từ cấm (nhóm cùng tên cộng dồn). Mặc định: file + hồ sơ thương hiệu + mục "Không được nói" của kho (AG-B3). */
final class CompositeBannedTerms implements BannedTermsProvider
{
    /** @var list<BannedTermsProvider> */
    private array $providers;

    public function __construct(BannedTermsProvider ...$providers)
    {
        $this->providers = $providers ?: [new FileBannedTerms(), new BrandProfileBannedTerms(), new KnowledgeForbiddenTerms()];
    }

    public function termsFor(QualityContext $ctx): array
    {
        $out = [];
        foreach ($this->providers as $p) {
            foreach ($p->termsFor($ctx) as $group => $terms) {
                $out[$group] = array_values(array_unique(array_merge($out[$group] ?? [], $terms)));
            }
        }

        return $out;
    }
}
