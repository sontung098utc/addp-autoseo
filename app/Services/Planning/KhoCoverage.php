<?php

namespace App\Services\Planning;

use App\Models\Keyword;
use App\Models\Website;
use App\Services\Knowledge\KnowledgeRetriever;

/**
 * Kho kiến thức có nguồn đã duyệt cho một cụm hay không (tiêu chí CHẤT LƯỢNG: viết đúng cần có nguồn đáng tin).
 * Dùng chung cho đề xuất chiến dịch và khám phá chủ đề con.
 */
final class KhoCoverage
{
    public function __construct(private readonly KnowledgeRetriever $retriever = new KnowledgeRetriever) {}

    public function hasItems(Website $site): bool
    {
        try {
            return $this->retriever->searchable($site)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Số mục kiến thức đã duyệt khớp CHÍNH cụm này. Ngoài việc truy xuất theo từ khóa, mục phải chứa các từ riêng của cụm (ngoài từ của chủ đề):
     * kho có "đường huyết cao" thì chưa đủ để coi "đường huyết thấp" có nguồn.
     */
    public function matches(Website $site, string $kw, string $seed = ''): int
    {
        try {
            $own = array_values(array_filter(array_diff(self::words($kw), self::words($seed)), fn ($x) => mb_strlen($x) >= 2 && ! in_array($x, CampaignProposer::STOP, true)));
            $need = $own === [] ? 0 : (int) ceil(count($own) * 0.6);
            $n = 0;
            foreach ($this->retriever->search($site, $kw, 5) as $row) {
                $text = Keyword::keyFor((string) $row['title'].' '.(string) $row['body']);
                if (count(array_filter($own, fn ($w) => str_contains($text, $w))) >= $need) {
                    $n++;
                }
            }

            return $n;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<string> */
    private static function words(string $t): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', Keyword::keyFor($t), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
