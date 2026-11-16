<?php

namespace App\Services\Knowledge;

/**
 * Mở rộng truy vấn tiếng Việt sang từ khóa tiếng Anh tương đương (xem resources/knowledge/glossary-vi-en.php) để truy xuất không hụt
 * mục kiến thức còn là tiếng Anh. Thuần luật, tất định, 0 token. Chỉ thêm từ, không bao giờ bỏ từ của truy vấn gốc.
 */
final class KnowledgeGlossary
{
    /** @var array<string, list<string>>|null */
    private static ?array $map = null;

    /** @return array<string, list<string>> khóa đã chuẩn hóa, dài trước ngắn để cụm dài khớp trước */
    private static function map(): array
    {
        if (self::$map === null) {
            $raw = (array) (@include resource_path('knowledge/glossary-vi-en.php'));
            $norm = [];
            foreach ($raw as $k => $en) {
                $norm[KnowledgeDedupe::normalize((string) $k)] = array_values(array_map('mb_strtolower', (array) $en));
            }
            uksort($norm, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            self::$map = $norm;
        }

        return self::$map;
    }

    /**
     * Từ/cụm tiếng Anh tương đương với các cụm tiếng Việt có trong $query.
     *
     * @return list<string> chữ thường, không trùng; [] khi không khớp cụm nào
     */
    public static function expand(string $query): array
    {
        $out = [];
        foreach (self::concepts($query) as $alts) {
            foreach ($alts as $e) {
                $out[$e] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Mỗi cụm tiếng Việt khớp trong $query là một "khái niệm" gồm các cách nói tiếng Anh tương đương; chấm điểm theo số khái niệm được phủ
     * (khớp bất kỳ cách nói nào của khái niệm) để danh sách từ dài không làm loãng điểm.
     *
     * @return list<list<string>>
     */
    public static function concepts(string $query): array
    {
        $q = ' '.KnowledgeDedupe::normalize($query).' ';
        $out = [];
        foreach (self::map() as $vi => $en) {
            if ($vi !== '' && str_contains($q, ' '.$vi.' ')) {
                $out[] = $en;
            }
        }

        return $out;
    }
}
