<?php

namespace App\Services\Content\Quality;

/**
 * Danh sách mặc định từ file, cộng thêm lớp ghi đè truyền vào (website rồi chiến dịch, lớp sau cộng dồn vào nhóm cùng tên).
 * Chiến dịch chỉ THÊM từ cấm; tắt một từ cấm của website phải sửa ở website, để không vô tình mở cổng.
 */
final class FileBannedTerms implements BannedTermsProvider
{
    /**
     * @param  array<string, list<string>>|null  $base
     * @param  list<array<string, list<string>>>  $overrides
     */
    public function __construct(private ?array $base = null, private array $overrides = [])
    {
    }

    public function termsFor(QualityContext $ctx): array
    {
        $base = $this->base ?? (require resource_path('playbooks/rules/banned-terms.php'))['terms'];
        $out = $base;
        foreach ($this->overrides as $layer) {
            foreach ($layer as $group => $terms) {
                $out[$group] = array_values(array_unique(array_merge($out[$group] ?? [], $terms)));
            }
        }

        return $out;
    }
}
