<?php

namespace App\Services\Content;

use App\Models\Website;
use App\Services\Knowledge\Discover\SourceDiscoveryService;
use App\Services\Search\WebSearchUnavailable;

/**
 * Tư liệu tham khảo bậc 1 cho NGƯỜI VIẾT: tìm web (chỉ nguồn mà luật `SourceTrust` đã xếp "tin cậy") và đưa đoạn trích tìm được vào lời nhắn
 * để số liệu/ngưỡng/khuyến cáo dựa trên tài liệu thật thay vì trí nhớ của model. Chỉ là chất liệu hỗ trợ viết: KHÔNG nạp vào kho, KHÔNG tự phong
 * "đã duyệt", KHÔNG chặn gì. Không tìm được (chưa có khóa, lỗi mạng, không có kết quả) thì trả danh sách rỗng kèm lý do.
 * Kết quả tìm kiếm GIẢ LẬP (môi trường local) không bao giờ được đưa vào lời nhắn: dữ liệu giả không được giả làm tài liệu thật.
 */
final class ReferenceDossier
{
    public const MAX = 4;

    public const MIN_SNIPPET_CHARS = 160;

    public const EXCERPT_CHARS = 1000;

    public function __construct(private readonly ?SourceDiscoveryService $discovery = null)
    {
    }

    /**
     * @return array{status: string, references: list<array{title: string, domain: string, url: string, excerpt: string}>, reason: ?string, cost_vnd: float, provider: ?string, query: ?string}
     */
    public function gather(Website $site, string $keyword): array
    {
        $out = ['status' => 'none', 'references' => [], 'reason' => null, 'cost_vnd' => 0.0, 'provider' => null, 'query' => null];
        try {
            $d = ($this->discovery ?? new SourceDiscoveryService)->discover($site, $keyword, ['max_results' => 8]);
        } catch (WebSearchUnavailable $e) {
            return ['status' => 'unavailable', 'reason' => mb_substr($e->getMessage(), 0, 200)] + $out;
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'error', 'reason' => 'Tìm tài liệu lỗi: '.class_basename($e)] + $out;
        }
        $out['cost_vnd'] = (float) $d['cost_vnd'];
        $out['provider'] = $d['provider'] ?: null;
        $out['query'] = $d['query'];
        if (! empty($d['demo'])) {
            return ['status' => 'demo', 'reason' => 'Tìm kiếm đang ở chế độ mô phỏng nên không dùng làm tài liệu.'] + $out;
        }
        foreach ($d['candidates'] as $c) {
            $excerpt = self::clean((string) $c['snippet']);
            if (($c['trust']['level'] ?? '') !== 'trusted' || mb_strlen($excerpt) < self::MIN_SNIPPET_CHARS) {
                continue;
            }
            $out['references'][] = ['title' => self::clean((string) $c['title'], 160), 'domain' => (string) $c['domain'], 'url' => (string) $c['url'], 'excerpt' => mb_substr($excerpt, 0, self::EXCERPT_CHARS)];
            if (count($out['references']) >= self::MAX) {
                break;
            }
        }
        $out['status'] = $out['references'] === [] ? 'none' : 'found';
        $out['reason'] = $out['references'] === [] ? 'Không có tài liệu từ nguồn tin cậy đủ nội dung.' : null;

        return $out;
    }

    /** Làm phẳng chuỗi đưa vào lời nhắn: bỏ thẻ, ký tự điều khiển và dấu khung. */
    private static function clean(string $s, int $max = 4000): string
    {
        $t = preg_replace(['/[\x00-\x1F\x7F]+/u', '/\s+/u', '/-{3,}/u'], [' ', ' ', '—'], strip_tags($s)) ?? '';

        return mb_substr(trim(str_replace(['<<<', '>>>'], ['‹‹‹', '›››'], $t)), 0, $max);
    }
}
