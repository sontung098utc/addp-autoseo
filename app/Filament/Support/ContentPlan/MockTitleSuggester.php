<?php

namespace App\Filament\Support\ContentPlan;

/** Gợi ý bài MINH HỌA, xác định: chỉ đề xuất tiêu đề + từ khóa chính, không gọi AI, không tạo bài. */
final class MockTitleSuggester
{
    /** @param  list<array{keyword:string, intent:string}>  $keywords @return list<array{keyword:string, title:string, intent:string}> */
    public function suggest(array $keywords, int $max = 5): array
    {
        $out = [];
        foreach (array_slice($keywords, 0, $max) as $k) {
            $kw = $k['keyword'];
            $t = mb_strtoupper(mb_substr($kw, 0, 1)).mb_substr($kw, 1);
            $out[] = ['keyword' => $kw, 'intent' => $k['intent'], 'title' => match ($k['intent']) {
                'commercial' => $t.': so sánh và cách chọn',
                'transactional' => $t.': hướng dẫn từng bước',
                default => $t.': giải đáp đầy đủ',
            }];
        }

        return $out;
    }
}
