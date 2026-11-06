<?php

namespace App\Services\Content\Quality;

/**
 * Ngưỡng của bộ kiểm tra chất lượng, gom một chỗ để chỉnh/hiệu chỉnh sau.
 * Các giá trị chọn theo kinh nghiệm SEO phổ biến, CHƯA hiệu chỉnh trên dữ liệu thật của công ty.
 */
final class QualityThresholds
{
    /** LEN01: số từ (token tách theo khoảng trắng; tiếng Việt mỗi âm tiết là một từ). */
    public const MIN_WORDS = 800;
    public const BLOCKING_WORDS = 400;

    /** HEAD01 */
    public const MIN_H2 = 2;
    public const MAX_H1 = 1;

    /** KW01 */
    public const KEYWORD_LEAD_WORDS = 100;
    public const MAX_KEYWORD_DENSITY_PERCENT = 3.0;

    /** META01 */
    public const MAX_SEO_TITLE_CHARS = 60;
    public const MIN_META_CHARS = 120;
    public const MAX_META_CHARS = 160;
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** DUP01 */
    public const SHINGLE_SIZE = 5;
    public const DUP_BLOCKING = 0.5;
    public const DUP_WARNING = 0.3;
    public const DUP_MAX_COMPARED = 200;
}
