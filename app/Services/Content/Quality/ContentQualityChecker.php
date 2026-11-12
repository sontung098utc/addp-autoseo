<?php

namespace App\Services\Content\Quality;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Services\Content\Quality\Rules\BannedTermsRule;
use App\Services\Content\Quality\Rules\DuplicateRule;
use App\Services\Content\Quality\Rules\CitationRule;
use App\Services\Content\Quality\Rules\EvidenceRule;
use App\Services\Content\Quality\Rules\MedicalSafetyRule;
use App\Services\Content\Quality\Rules\SourceRule;
use App\Services\Content\Quality\Rules\FactRule;
use App\Services\Content\Quality\Rules\FaqRule;
use App\Services\Content\Quality\Rules\HeadingRule;
use App\Services\Content\Quality\Rules\ImageRule;
use App\Services\Content\Quality\Rules\KeywordRule;
use App\Services\Content\Quality\Rules\KnowledgeFigureRule;
use App\Services\Content\Quality\Rules\LengthRule;
use App\Services\Content\Quality\Rules\LinkRule;
use App\Services\Content\Quality\Rules\MetaRule;
use App\Services\Content\Quality\Rules\SafetyRule;
use App\Services\Content\Quality\Rules\HeadingLeakRule;
use App\Services\Content\Quality\Rules\ImagePlaceholderRule;
use App\Services\Content\Quality\Rules\IntentSpecificityRule;
use App\Services\Content\Quality\Rules\PaddingRule;
use App\Services\Content\Quality\Rules\TextGlitchRule;

/**
 * Kiểm tra chất lượng bài tại thời điểm gọi (chưa lưu lịch sử kết quả).
 * Thêm luật mới: tạo lớp implements QualityRule rồi đưa vào defaultRules() hoặc truyền qua constructor.
 */
class ContentQualityChecker
{
    /** @var list<QualityRule> */
    private array $rules;

    /** @param list<QualityRule>|null $rules */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? self::defaultRules();
    }

    /** @return list<QualityRule> */
    public static function defaultRules(): array
    {
        return [
            new LengthRule(), new HeadingRule(), new KeywordRule(), new MetaRule(), new DuplicateRule(),
            new ImageRule(), new SafetyRule(), new FactRule(), new FaqRule(), new BannedTermsRule(), new KnowledgeFigureRule(), new LinkRule(), new EvidenceRule(), new CitationRule(), new MedicalSafetyRule(), new SourceRule(),
            new PaddingRule(), new TextGlitchRule(), new HeadingLeakRule(), new ImagePlaceholderRule(), new IntentSpecificityRule(),
        ];
    }

    /** @param list<int>|null $brainIds Trí tuệ do chiến dịch chọn (AG-B3); null = suy từ website của bài */
    public function check(ArticleVersion $version, ?Article $article = null, ?array $brainIds = null): QualityReport
    {
        $ctx = new QualityContext($version, $article ?? $version->article, $brainIds);
        $results = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($ctx) as $r) {
                $results[] = $r;
            }
        }

        return new QualityReport($results);
    }
}
