<?php

namespace App\Services\Agent\Eval;

/**
 * Q0.1: một từ khóa chuẩn và các CÂU HỎI BẮT BUỘC mà một bài tốt phải trả lời.
 * Chỉ ghi câu hỏi, KHÔNG ghi đáp án: đáp án lấy từ nguồn bậc 1 và do dược sĩ duyệt (status `nhap` = chưa duyệt).
 */
final class GoldenQuery
{
    public const KINDS = ['khai_niem', 'so_lieu', 'quy_trinh', 'canh_bao', 'so_sanh', 'gia', 'lua_chon'];

    public const RISKS = ['low', 'medium', 'high'];

    public const INTENTS = ['informational', 'commercial', 'transactional', 'navigational'];

    /**
     * @param  list<array{id:string,q:string,kind:string,needs_source:bool}>  $mustAnswer
     */
    public function __construct(
        public readonly string $id,
        public readonly string $keyword,
        public readonly string $intent,
        public readonly string $risk,
        public readonly string $domain,
        public readonly array $mustAnswer,
        public readonly string $note = '',
    ) {
    }

    /** Câu hỏi mà đáp án đúng buộc phải có con số/ngưỡng cụ thể (dùng để bắt kiểu trả lời "tùy bác sĩ" không nêu số). */
    public function needsNumber(string $questionId): bool
    {
        foreach ($this->mustAnswer as $q) {
            if ($q['id'] === $questionId) {
                return $q['kind'] === 'so_lieu';
            }
        }

        return false;
    }

    /** Chuẩn hóa từ khóa để so khớp bài với mẫu: chữ thường, gộp khoảng trắng. */
    public static function normalizeKeyword(string $keyword): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $keyword) ?? $keyword), 'UTF-8');
    }
}
