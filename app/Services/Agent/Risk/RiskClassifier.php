<?php

namespace App\Services\Agent\Risk;

use Illuminate\Support\Str;

/**
 * AG-K12: phân hạng rủi ro bằng LUẬT (không dùng AI), nên kết quả lặp lại được và không bị prompt injection điều khiển.
 * Mặc định an toàn: văn bản rỗng hoặc dữ liệu cấu hình hỏng => không bao giờ trả "thấp".
 */
final class RiskClassifier
{
    /** @var array{topics: array<string, array<string, list<string>>>, sensitive_claims: array<string, list<string>>} */
    private array $config;

    private bool $asciiMode = false;

    public function __construct(?array $config = null)
    {
        $config ??= require resource_path('playbooks/risk/topics.php');
        $this->config = $config;
    }

    public function assess(string $title, string $body = ''): RiskAssessment
    {
        $text = $this->normalize($title.' '.strip_tags($body));
        // Chỉ bỏ dấu khi văn bản vốn gõ không dấu; có dấu thì giữ nguyên để "thuê" không trùng "thuế".
        $this->asciiMode = Str::ascii($text) === $text;
        if (trim($text) === '') {
            return new RiskAssessment(RiskAssessment::HIGH, ['Không có nội dung để phân hạng nên coi là rủi ro cao.'], []);
        }

        $reasons = [];
        $level = RiskAssessment::LOW;
        foreach ([RiskAssessment::HIGH, RiskAssessment::MEDIUM] as $tier) {
            foreach ($this->config['topics'][$tier] ?? [] as $group => $phrases) {
                $hit = $this->firstMatch($text, $phrases);
                if ($hit !== null) {
                    $reasons[] = "Chủ đề {$this->groupLabel($group)} (có cụm \"{$hit}\")";
                    $level = $level === RiskAssessment::HIGH ? $level : $tier;
                }
            }
        }

        $claims = [];
        foreach ($this->config['sensitive_claims'] ?? [] as $group => $phrases) {
            $hit = $this->firstMatch($text, $phrases);
            if ($hit !== null) {
                $claims[] = ['group' => $group, 'phrase' => $hit];
            }
        }
        if ($claims !== [] && $level === RiskAssessment::LOW) {
            $level = RiskAssessment::MEDIUM;
            $reasons[] = 'Có claim nhạy cảm cần nguồn hoặc phải bỏ.';
        }

        return new RiskAssessment($level, $reasons, $claims);
    }

    /** @param list<string> $phrases */
    private function firstMatch(string $text, array $phrases): ?string
    {
        foreach ($phrases as $p) {
            $n = $this->normalize($p);
            if ($this->asciiMode) {
                $n = Str::ascii($n);
            }
            if ($n !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($n, '/').'(?![\p{L}\p{N}])/u', $text)) {
                return $p;
            }
        }

        return null;
    }

    /** Hạ chữ thường, gom khoảng trắng. */
    private function normalize(string $s): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower($s)) ?? '';
    }

    private function groupLabel(string $group): string
    {
        return match ($group) {
            'suc_khoe' => 'sức khỏe', 'tai_chinh' => 'tài chính', 'phap_ly' => 'pháp lý', 'an_toan' => 'an toàn',
            'gia_ca' => 'giá cả', 'so_sanh' => 'so sánh/xếp hạng', 'so_lieu' => 'số liệu thống kê',
            default => $group,
        };
    }
}
