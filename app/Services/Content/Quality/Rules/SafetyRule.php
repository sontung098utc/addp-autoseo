<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/** Chỉ BÁO nội dung nguy hiểm; việc lọc/sanitize thuộc HtmlSanitizer (Dev1). */
class SafetyRule implements QualityRule
{
    public function code(): string
    {
        return 'SAFE01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $found = [];
        $dom = $ctx->dom();
        foreach ($ctx->xpath()->query('//script|//iframe|//object|//embed') ?: [] as $n) {
            $found[] = '<' . $n->nodeName . '> — ' . QualityContext::excerpt((string) $dom->saveHTML($n), 100);
        }
        foreach ($ctx->xpath()->query('//*[@*]') ?: [] as $el) {
            foreach ($el->attributes as $a) {
                $name = strtolower($a->nodeName);
                $val = preg_replace('/[\x00-\x20]+/', '', strtolower($a->nodeValue)) ?? '';
                if (str_starts_with($name, 'on')) {
                    $found[] = "thuộc tính {$name} trong <{$el->nodeName}> — " . QualityContext::excerpt($a->nodeValue, 80);
                } elseif (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true) && str_starts_with($val, 'javascript:')) {
                    $found[] = "URL javascript: trong <{$el->nodeName} {$name}>";
                }
            }
        }
        $n = count($found);

        return [new RuleResult('SAFE01', RuleResult::P0, $n === 0, 'Nội dung nguy hiểm', $n === 0
            ? 'Không thấy script, iframe, thuộc tính on*, URL javascript:.'
            : "Phát hiện {$n} thành phần nguy hiểm (script/iframe/on*/javascript:). Loại bỏ trước khi duyệt; nội dung này không được phép đăng lên CMS.",
            "{$n} mục", '0 mục', $n === 0 ? null : implode('; ', array_slice($found, 0, 5)))];
    }
}
