<?php

namespace App\Services\Brand;

use App\Models\ArticleVersion;
use App\Models\BrandProfile;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemUsage;
use App\Services\Brain\BrainContext;
use Illuminate\Support\Str;

/**
 * "Bài này đã bám bản sắc thương hiệu và quy tắc lĩnh vực chưa?": bảng cho người duyệt, thuần LUẬT (0 token, không AI, không phán đoán văn phong).
 * Chỉ nói điều kiểm được: thông điệp chính có xuất hiện không (so từ khóa nội dung, không phải đồng nghĩa), từ cấm có lọt không, hồ sơ doanh nghiệp đầy đến đâu,
 * Trí tuệ và quy tắc chuyên gia nào đã được đưa cho AI. Giọng văn chỉ hiển thị lại cấu hình, KHÔNG kết luận "đúng giọng".
 */
final class BrandCompliance
{
    /** @return array{brand: array<string,mixed>, domain: array<string,mixed>, notes: list<string>} */
    public static function forVersion(ArticleVersion $v): array
    {
        $article = $v->article;
        $text = self::norm(strip_tags((string) $v->html_content));
        $profile = $article ? BrandProfileData::forWebsite($article->website_id) : null;
        $completeness = BrandProfileData::completeness($profile);

        $messages = [];
        foreach ((array) $profile?->key_messages as $m) {
            $m = trim((string) $m);
            if ($m !== '') {
                $messages[] = ['text' => $m, 'covered' => self::covers($text, $m)];
            }
        }
        $forbidden = [];
        foreach ((array) $profile?->forbidden_terms as $t) {
            $t = trim((string) $t);
            if ($t !== '' && str_contains($text, self::norm($t))) {
                $forbidden[] = $t;
            }
        }

        $brains = $article ? BrainContext::forArticle($article)->brains : [];
        $usages = KnowledgeItemUsage::query()->where('article_version_id', $v->id)->with('item:id,type,title,entity')->get();
        $rules = $usages->filter(fn ($u) => $u->item && in_array($u->item->type, KnowledgeItem::RULE_TYPES, true))
            ->map(fn ($u) => ['title' => $u->item->title ?: $u->item->entity, 'type' => $u->item->type])->values()->all();

        $notes = [];
        if ($profile === null || $completeness['percent'] === 0) {
            $notes[] = 'Website chưa có hồ sơ doanh nghiệp: không có thông điệp, giọng hay từ cấm để đối chiếu.';
        }
        if ($forbidden !== []) {
            $notes[] = 'Bài chứa từ cấm: '.implode(', ', $forbidden).'.';
        }
        $missed = array_values(array_filter($messages, fn ($m) => ! $m['covered']));
        if ($messages !== [] && count($missed) === count($messages)) {
            $notes[] = 'Chưa thấy thông điệp chính nào của thương hiệu xuất hiện trong bài.';
        }

        return [
            'brand' => [
                'profile_percent' => $completeness['percent'], 'missing' => $completeness['missing'],
                'voice' => $profile?->voice ? (BrandProfile::VOICES[$profile->voice] ?? null) : null,
                'audience' => $profile ? (string) $profile->audience : '',
                'messages' => $messages, 'forbidden_hits' => $forbidden,
            ],
            'domain' => [
                'brains' => array_map(fn ($b) => ['name' => $b->name, 'reliance' => $b->reliance(), 'risk' => $b->riskClass()], $brains),
                'rules_applied' => $rules,
                'knowledge_items' => $usages->count(),
            ],
            'notes' => $notes,
        ];
    }

    private static function norm(string $s): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    /** Thông điệp được coi là có mặt khi ≥ 60% các từ nội dung (≥ 3 ký tự) của nó xuất hiện trong bài. Luật thô: không hiểu đồng nghĩa. */
    private static function covers(string $text, string $message): bool
    {
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', self::norm($message), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn ($w) => mb_strlen($w) >= 3)));
        if ($words === []) {
            return false;
        }
        $hit = count(array_filter($words, fn ($w) => str_contains($text, $w)));

        return $hit / count($words) >= 0.6;
    }
}
