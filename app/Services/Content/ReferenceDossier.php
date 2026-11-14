<?php

namespace App\Services\Content;

use App\Models\Website;
use App\Services\Knowledge\Discover\SourceDiscoveryService;
use App\Services\Search\WebSearchUnavailable;

/**
 * Tư liệu tham khảo bậc 1 cho NGƯỜI VIẾT và bước tổng hợp: tìm web theo NHIỀU GÓC (hướng dẫn chính thức, bằng chứng/nghiên cứu, lời khuyên thực hành)
 * để có nhiều nguồn uy tín khác nhau, chỉ giữ nguồn mà luật `SourceTrust` đã xếp "tin cậy", tối đa MAX_PER_DOMAIN trang mỗi tên miền.
 * Chỉ là chất liệu hỗ trợ viết: KHÔNG nạp vào kho, KHÔNG tự phong "đã duyệt", KHÔNG chặn gì. Không tìm được (chưa có khóa, lỗi mạng, không có kết quả)
 * thì trả danh sách rỗng kèm lý do. Kết quả tìm kiếm GIẢ LẬP (môi trường local) không bao giờ được dùng: dữ liệu giả không được giả làm tài liệu thật.
 */
final class ReferenceDossier
{
    public const MAX = 8;

    public const MAX_PER_DOMAIN = 2;

    public const MIN_SNIPPET_CHARS = 120;

    public const EXCERPT_CHARS = 1000;

    /** Hậu tố truy vấn cho từng góc tìm; '' = từ khóa gốc (SourceDiscoveryService tự thêm gợi ý lĩnh vực). */
    public const ANGLES = ['', ' nghiên cứu bằng chứng tổng quan', ' lời khuyên thực hành từ chuyên gia'];

    public function __construct(private readonly ?SourceDiscoveryService $discovery = null)
    {
    }

    /**
     * @param  list<string>|null  $topics  chủ đề tìm cụ thể (vd. các khoảng trống kiến thức); null = từ khóa theo ANGLES
     * @param  bool  $allowNeutral  chủ đề KHÔNG nhạy cảm (máy móc, phần mềm...): nhận cả nguồn thường chưa thuộc danh sách tin cậy (nhãn trust=neutral để bước tổng hợp đối chiếu thận trọng)
     * @return array{status: string, references: list<array{title: string, domain: string, url: string, excerpt: string, trust?: string}>, reason: ?string, cost_vnd: float, provider: ?string, query: ?string}
     */
    public function gather(Website $site, string $keyword, ?array $topics = null, bool $allowNeutral = false): array
    {
        $topics ??= array_map(fn ($a) => trim($keyword.$a), self::ANGLES);
        $out = ['status' => 'none', 'references' => [], 'reason' => null, 'cost_vnd' => 0.0, 'provider' => null, 'query' => null];
        $seen = [];
        $perDomain = [];
        foreach ($topics as $i => $topic) {
            $r = $this->attempt($site, $topic, $allowNeutral);
            $out['cost_vnd'] += $r['cost_vnd'];
            $out['provider'] ??= $r['provider'];
            $out['query'] ??= $r['query'];
            if (in_array($r['status'], ['unavailable', 'error', 'demo', 'quota'], true)) {
                if ($out['references'] === []) {
                    return ['status' => $r['status'], 'reason' => $r['reason']] + $out;
                }
                break; // đã có tài liệu từ các góc trước: giữ, không bỏ phí
            }
            foreach ($r['references'] as $ref) {
                if (isset($seen[$ref['url']]) || ($perDomain[$ref['domain']] ?? 0) >= self::MAX_PER_DOMAIN) {
                    continue;
                }
                $seen[$ref['url']] = true;
                $perDomain[$ref['domain']] = ($perDomain[$ref['domain']] ?? 0) + 1;
                $out['references'][] = $ref;
            }
            if (count($out['references']) >= self::MAX) {
                break;
            }
            if ($i === 0 && $out['references'] === [] && count($topics) === 1) { // kết quả tìm web thay đổi giữa các lần gọi: thử thêm một lần
                $again = $this->attempt($site, $topic, $allowNeutral);
                $out['cost_vnd'] += $again['cost_vnd'];
                foreach ($again['references'] as $ref) {
                    $out['references'][] = $ref;
                }
            }
        }
        $out['references'] = array_slice($out['references'], 0, self::MAX);
        $out['status'] = $out['references'] === [] ? 'none' : 'found';
        $out['reason'] = $out['references'] === [] ? 'Không có tài liệu từ nguồn tin cậy đủ nội dung.' : null;

        return $out;
    }

    /** @return array{status: string, references: list<array{title: string, domain: string, url: string, excerpt: string, trust?: string}>, reason: ?string, cost_vnd: float, provider: ?string, query: ?string} */
    private function attempt(Website $site, string $keyword, bool $allowNeutral = false): array
    {
        $out = ['status' => 'none', 'references' => [], 'reason' => null, 'cost_vnd' => 0.0, 'provider' => null, 'query' => null];
        try {
            $d = ($this->discovery ?? new SourceDiscoveryService)->discover($site, $keyword, ['max_results' => 8]);
        } catch (WebSearchUnavailable $e) {
            return ['status' => $e->getCode() === 429 ? 'quota' : 'unavailable', 'reason' => mb_substr($e->getMessage(), 0, 200)] + $out;
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
            $level = (string) ($c['trust']['level'] ?? '');
            if (! ($level === 'trusted' || ($allowNeutral && $level === 'neutral')) || mb_strlen($excerpt) < self::MIN_SNIPPET_CHARS) {
                continue;
            }
            $out['references'][] = ['title' => self::clean((string) $c['title'], 160), 'domain' => (string) $c['domain'], 'url' => (string) $c['url'], 'excerpt' => mb_substr($excerpt, 0, self::EXCERPT_CHARS), 'trust' => $level];
        }
        $out['status'] = $out['references'] === [] ? 'none' : 'found';

        return $out;
    }

    /** Làm phẳng chuỗi đưa vào lời nhắn: bỏ thẻ, ký tự điều khiển và dấu khung. */
    private static function clean(string $s, int $max = 4000): string
    {
        $t = preg_replace(['/[\x00-\x1F\x7F]+/u', '/\s+/u', '/-{3,}/u'], [' ', ' ', '—'], strip_tags($s)) ?? '';

        return mb_substr(trim(str_replace(['<<<', '>>>'], ['‹‹‹', '›››'], $t)), 0, $max);
    }
}
