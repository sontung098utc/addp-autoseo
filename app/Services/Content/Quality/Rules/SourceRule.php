<?php

namespace App\Services\Content\Quality\Rules;

use App\Models\BrainSource;
use App\Models\KnowledgeItem;
use App\Services\Brain\BrainContext;
use App\Services\Brain\BrainSourceRegistry;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeRetriever;

/**
 * SRC01 "Nguồn AI nêu có thật" (P1, không bao giờ P0). Chạy cho MỌI bài, không phụ thuộc "Bắt buộc nguồn". Tất định, KHÔNG dùng AI, KHÔNG gọi mạng.
 * Mỗi nguồn trong sources_cited của bản đang chấm phải khớp tập nguồn CÓ THẬT (so khớp không phân biệt hoa/thường, theo tên miền hoặc tên chuẩn hóa):
 *  (a) nguồn kiến thức (tên, tiêu đề, tên miền của url) của các mục kiến thức đã duyệt trong phạm vi website/Trí tuệ;
 *  (b) Sổ nguồn đã xác nhận (không gồm nguồn bị chặn: EVID01 lo, không nhân đôi cờ);
 *  (c) tên và tên miền của chính website.
 * Giới hạn: lúc chấm bản chưa lưu nên chưa có knowledge_item_usages; dùng mọi mục đã duyệt còn hiệu lực trong phạm vi (cùng $brainIds của checker), không chỉ mục đã nạp.
 * Chỉ đối chiếu tên/tên miền; không kiểm nội dung nguồn, không kiểm từng câu.
 */
class SourceRule implements QualityRule
{
    public function code(): string
    {
        return 'SRC01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $label = 'Nguồn AI nêu có thật';
        $cited = FactRule::sources($ctx->version->sources_cited);
        if ($cited === []) {
            return [];
        }
        $article = $ctx->article;
        $site = $article?->website;
        $ids = $article ? array_values(array_unique(array_merge(BrainContext::forArticle($article)->ids(), array_map('intval', $ctx->brainIds ?? [])))) : [];
        $registry = BrainSourceRegistry::forBrains($ids);

        $names = [];
        $domains = [];
        $add = function (?string $name, ?string $domain = null) use (&$names, &$domains) {
            if ($name !== null && ($n = KnowledgeDedupe::normalize($name)) !== '' && mb_strlen($n) >= 4) {
                $names[$n] = true;
            }
            if ($domain !== null && ($h = BrainSourceRegistry::hostOf($domain)) !== null) {
                $domains[$h] = true;
            }
        };
        if ($site) {
            $add($site->name, $site->base_url);
            foreach ((new KnowledgeRetriever)->eligible($site, $ctx->brainIds)->limit(2000)->get() as $item) {
                /** @var KnowledgeItem $item */
                $add($item->title);
                if ($item->source) {
                    $add($item->source->title, $item->source->url);
                }
            }
        }
        foreach ($registry->citable() as $s) {
            /** @var BrainSource $s */
            $add($s->name, $s->domain);
        }

        $unmatched = [];
        foreach ($cited as $c) {
            $hit = $registry->classify($c);
            if ($hit !== null && $hit['tier'] === 'blocked') {
                continue; // EVID01 xử lý nguồn bị chặn
            }
            if (! $this->known($c, $names, $domains)) {
                $unmatched[] = $c;
            }
        }
        if ($unmatched === []) {
            return [new RuleResult('SRC01', RuleResult::P1, true, $label, 'Mọi nguồn AI nêu đều khớp kho kiến thức, Sổ nguồn hoặc website (chỉ đối chiếu tên/tên miền, không kiểm nội dung nguồn).', count($cited).' nguồn')];
        }
        $list = implode('; ', array_map(fn ($u) => '"'.mb_substr($u, 0, 80).'"', array_slice($unmatched, 0, 3)));

        return [new RuleResult('SRC01', RuleResult::P1, false, $label,
            count($unmatched).'/'.count($cited).' nguồn không khớp kho kiến thức, Sổ nguồn hay website: '.$list.'. AI tự nêu, chưa kiểm: hãy xác minh nguồn có thật trước khi duyệt, hoặc thêm vào Sổ nguồn nếu đúng.',
            count($unmatched).' nguồn chưa kiểm', '0 nguồn không khớp')];
    }

    /** @param array<string,true> $names @param array<string,true> $domains */
    private function known(string $cited, array $names, array $domains): bool
    {
        $hosts = [];
        if (($h = BrainSourceRegistry::hostOf($cited)) !== null) {
            $hosts[] = $h;
        }
        if (preg_match_all('~(?:https?://)?((?:[a-z0-9-]+\.)+[a-z]{2,})~iu', $cited, $m)) {
            foreach ($m[1] as $x) {
                if (($h = BrainSourceRegistry::hostOf($x)) !== null) {
                    $hosts[] = $h;
                }
            }
        }
        foreach (array_unique($hosts) as $h) {
            foreach (array_keys($domains) as $d) {
                if ($h === $d || str_ends_with($h, '.'.$d)) {
                    return true;
                }
            }
        }
        $n = KnowledgeDedupe::normalize($cited);
        if ($n === '') {
            return false;
        }
        foreach (array_keys($names) as $known) {
            if ($n === $known || (mb_strlen($n) >= 4 && (str_contains($known, $n) || str_contains($n, $known)))) {
                return true;
            }
        }

        return false;
    }
}
