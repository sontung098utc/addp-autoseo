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

    /** @var list<string>|null mã luật P0 được chặn; null = mọi luật P0 đều chặn */
    private ?array $blocking;

    /**
     * @param  list<QualityRule>|null  $rules  null = bộ luật mặc định và chính sách chặn theo config('quality.blocking_codes')
     * @param  list<string>|string|null  $blockingCodes  null = theo ngữ cảnh trên; truyền rõ bộ luật thì mặc định chặn mọi P0 (dùng trong test)
     */
    public function __construct(?array $rules = null, array|string|null $blockingCodes = null)
    {
        $this->blocking = $blockingCodes !== null
            ? self::parseBlocking($blockingCodes)
            : ($rules === null ? self::parseBlocking((string) config('quality.blocking_codes', 'ALL')) : null);
        $this->rules = $rules ?? self::defaultRules();
    }

    /** @return list<string>|null */
    private static function parseBlocking(array|string $v): ?array
    {
        $list = is_array($v) ? $v : array_map('trim', explode(',', $v));
        $list = array_values(array_filter(array_map('strtoupper', $list)));

        return in_array('ALL', $list, true) || $list === [] ? null : $list;
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
                // Luật P0 không nằm trong danh sách chặn: hạ xuống cảnh báo (P1), người duyệt vẫn thấy.
                if ($this->blocking !== null && ! $r->passed && $r->severity === RuleResult::P0 && ! in_array($r->code, $this->blocking, true)) {
                    $r = new RuleResult($r->code, RuleResult::P1, false, $r->label, rtrim($r->message).' (Chỉ cảnh báo, không chặn.)', $r->measured, $r->threshold, $r->excerpt, $r->details);
                }
                $results[] = $r;
            }
        }

        return new QualityReport($results);
    }
}
