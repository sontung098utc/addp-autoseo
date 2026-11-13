<?php

namespace App\Filament\Support\Agent;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;

/**
 * "Đầu vào / Đầu ra" của MỘT chặng trong ngăn chi tiết phiên chạy: NỘI DUNG thật (phân tích, dàn ý, kế hoạch ảnh, bài viết, lỗi chấm, vị trí lắp ráp),
 * không chỉ con số. Lấy từ dữ liệu đã ghi ở bước; thiếu thì bỏ khối đó, không bịa. Với bước gọi AI, lời nhắc đã gửi và phản hồi nguyên văn
 * (có thể bị cắt cuối) nằm trong khối "long" gập sẵn. Không trả HTML: nơi hiển thị tự escape.
 *
 * Khối: ['label' => string, 'type' => text|list|long|outline|plan|images|placements, 'value' => mixed]
 */
final class StepIo
{
    private const INTENTS = ['informational' => 'thông tin', 'commercial' => 'so sánh, cân nhắc', 'transactional' => 'mua, đặt dịch vụ', 'navigational' => 'tìm đúng trang'];

    /** @return array{in: list<array{label:string,type:string,value:mixed}>, out: list<array{label:string,type:string,value:mixed}>} */
    public static function for(AgentRunStep $s, AgentRun $run): array
    {
        $d = (array) $s->data;
        $a = $run->article;
        $in = [];
        $out = [];
        $add = static function (array &$list, string $label, string $type, mixed $value): void {
            if ($value === null || $value === '' || $value === []) {
                return;
            }
            $list[] = ['label' => $label, 'type' => $type, 'value' => $value];
        };
        $topic = static function () use (&$in, $add, $a): void {
            $add($in, 'Từ khóa chính', 'text', $a?->primary_keyword);
            $add($in, 'Từ khóa phụ', 'text', implode(', ', array_slice((array) ($a?->secondary_keywords ?? []), 0, 8)));
            $intent = (string) ($a?->search_intent ?? '');
            $add($in, 'Ý định tìm kiếm', 'text', self::INTENTS[$intent] ?? $intent);
        };
        $exchange = (array) ($d['exchange'] ?? []);
        $ai = static function () use (&$in, &$out, $add, $exchange): void {
            $add($in, 'Lời nhắc gửi cho AI (hệ thống)', 'long', $exchange['system'] ?? null);
            $add($in, 'Yêu cầu gửi cho AI', 'long', $exchange['user'] ?? null);
            $add($out, 'Phản hồi nguyên văn của AI', 'long', $exchange['response'] ?? null);
        };
        $briefOutline = static function () use ($run): array {
            $b = $run->steps->where('stage', 'brief')->last();

            return (array) (((array) ($b?->data ?? []))['outline'] ?? []);
        };
        $count = static fn (string $k): int => (int) (($d[$k]['count'] ?? null) ?? count((array) ($d[$k]['suggested'] ?? $d[$k]['selected'] ?? [])));

        switch ($s->stage) {
            case 'guard':
                $add($in, 'Cách bắt đầu', 'text', $run->trigger === 'schedule' ? 'theo lịch' : 'bấm tay');
                $add($out, 'Kết quả', 'text', trim($s->title.'. '.$s->detail));
                break;
            case 'risk':
                $topic();
                $add($out, 'Hạng rủi ro', 'text', ['low' => 'thấp', 'medium' => 'trung bình', 'high' => 'cao'][$d['level'] ?? ''] ?? ($d['level'] ?? null));
                $add($out, 'Phân tích', 'text', $s->detail);
                break;
            case 'brief':
                $topic();
                $add($out, 'Phân tích và ghi chú', 'text', $s->detail);
                $add($out, 'Dàn ý', 'outline', (array) ($d['outline'] ?? []));
                // Kế hoạch ảnh là một phần của dàn ý: cần ảnh nào, sau mục nào, prompt ra sao (hoặc vì sao không tạo ảnh).
                $add($out, 'Kế hoạch ảnh (theo dàn ý)', 'plan', (array) ($d['image_plan']['plan']['slots'] ?? []));
                $add($out, 'Không tạo ảnh vì', 'text', $d['image_plan']['skipped_reason'] ?? null);
                $ai();
                break;
            case 'research':
                $topic();
                $add($out, 'Kiến thức đã duyệt', 'text', isset($d['knowledge']) ? $count('knowledge').' mục'.(! empty($d['knowledge']['gap']) ? ' — thiếu kiến thức khớp chủ đề' : '') : null);
                $add($out, 'Nguồn được phép trích', 'list', array_map(fn ($c) => is_array($c) ? trim(($c['title'] ?? $c['name'] ?? '').' '.($c['url'] ?? '')) : (string) $c, (array) ($d['citable_sources'] ?? [])));
                $add($out, 'Liên kết nội bộ gợi ý', 'list', array_map(fn ($l) => trim(($l['anchor'] ?? '').' → '.($l['url'] ?? '')), (array) ($d['internal_links']['suggested'] ?? [])));
                $add($out, 'Ảnh thư viện khớp chủ đề', 'list', array_map(fn ($i) => trim(($i['alt'] ?? '').' — '.($i['ly_do'] ?? '')), (array) ($d['images']['selected'] ?? [])));
                $add($out, 'Ghi chú', 'text', $s->detail);
                break;
            case 'write':
                $topic();
                $add($in, 'Dàn ý', 'outline', $briefOutline());
                $ai();
                $parsed = self::parseArticle((string) ($exchange['response'] ?? ''));
                $add($out, 'Tiêu đề / SEO title', 'text', $parsed ? trim(($parsed['title'] ?? '')."\n".($parsed['seo_title'] ?? '')) : null);
                $add($out, 'Meta description', 'text', $parsed['meta'] ?? null);
                $add($out, 'Cấu trúc bài', 'list', $parsed['headings'] ?? []);
                $add($out, 'Đoạn mở đầu', 'text', $parsed['intro'] ?? null);
                $add($out, 'Số từ', 'text', isset($d['words']) ? number_format((int) $d['words'], 0, ',', '.') : null);
                $add($out, 'Ghi chú', 'text', $s->detail);
                break;
            case 'grade':
                $add($in, 'Bản nháp được chấm', 'text', ($d['round'] ?? 0) > 0 ? 'Bản sau sửa vòng '.$d['round'] : 'Bản đầu tiên của người viết');
                $add($in, 'Ngưỡng tham khảo', 'text', isset($d['threshold']) ? $d['threshold'].'/100' : null);
                $add($out, 'Điểm', 'text', isset($d['score']) ? $d['score'].'/100' : null);
                $add($out, 'Lỗi tìm thấy', 'list', array_map(fn ($f) => trim(($f['code'] ?? '').' ('.($f['severity'] ?? '').') '.($f['message'] ?? '')), (array) ($d['failed'] ?? [])));
                $add($out, 'Luật chặn', 'text', implode(', ', array_map(fn ($b) => is_array($b) ? ($b['code'] ?? '') : (string) $b, (array) ($d['blocking'] ?? []))));
                break;
            case 'images':
                $add($in, 'Kế hoạch ảnh', 'plan', (array) ($d['plan']['slots'] ?? []));
                $add($in, 'Nhà cung cấp ảnh', 'text', $d['generator'] ?? null);
                $add($out, 'Ảnh đã tạo', 'images', self::images((array) ($d['items'] ?? [])));
                $add($out, 'Lý do không tạo', 'text', $d['skipped_reason'] ?? null);
                $add($out, 'Lỗi', 'list', array_slice((array) ($d['errors'] ?? []), 0, 3));
                break;
            case 'assemble':
                $add($in, 'Bài viết và ảnh đã tạo', 'text', isset($d['version_id']) ? 'Phiên bản #'.$d['version_id'].' + ảnh theo kế hoạch ảnh' : null);
                $add($out, 'Vị trí từng ảnh', 'placements', (array) ($d['placements'] ?? []));
                break;
            case 'handoff':
                $add($in, 'Phiên bản', 'text', isset($d['version']) ? 'v'.$d['version'] : null);
                $add($out, 'Kết quả', 'text', trim($s->title.'. '.$s->detail));
                break;
        }

        // Thời gian ĐO THẬT (đồng hồ máy chủ) của bước; phiên cũ chưa có thì bỏ khối này, không bịa.
        if (is_numeric($d['duration_ms'] ?? null)) {
            $fmt = static fn (float $ms): string => $ms < 1000 ? '<1 giây' : number_format($ms / 1000, 1, ',', '.').' giây';
            $note = $fmt((float) $d['duration_ms']);
            if (is_numeric($d['ai_ms'] ?? null) && (float) $d['ai_ms'] > 0) {
                $note .= ', trong đó chờ AI '.$fmt((float) $d['ai_ms']).' (kể cả thử lại)';
            }
            $add($out, 'Thời gian đo thật', 'text', $note);
        }

        return ['in' => $in, 'out' => $out];
    }

    /** Đọc phản hồi JSON của AI viết bài (có thể bị cắt cuối): tiêu đề, meta, cấu trúc đề mục kèm số từ, đoạn mở đầu. */
    private static function parseArticle(string $response): array
    {
        $j = json_decode($response, true);
        if (! is_array($j) && preg_match('/\{.*\}/su', $response, $m)) {
            $j = json_decode($m[0], true);
        }
        $html = is_array($j) && is_string($j['html_content'] ?? null) ? $j['html_content'] : '';
        if ($html === '' && preg_match('/"html_content"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/su', $response, $m)) {
            $html = (string) json_decode('"'.$m[1].'"');
        }
        if ($html === '') {
            return [];
        }
        $parts = preg_split('/(<h[23][^>]*>.*?<\/h[23]>)/isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $headings = [];
        $intro = '';
        $words = static fn (string $h) => count(preg_split('/\s+/u', trim(strip_tags($h)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        for ($i = 0; $i < count($parts); $i++) {
            if (preg_match('/^<h([23])[^>]*>(.*?)<\/h[23]>$/isu', $parts[$i], $m)) {
                $body = $parts[$i + 1] ?? '';
                $headings[] = ($m[1] === '3' ? '   ' : '').trim(html_entity_decode(strip_tags($m[2]))).' — '.$words($body).' từ';
            } elseif ($headings === [] && $intro === '') {
                $intro = mb_substr(trim(html_entity_decode(strip_tags($parts[$i]))), 0, 500);
            }
        }

        return ['title' => is_array($j) ? ($j['title'] ?? null) : null, 'seo_title' => is_array($j) ? ($j['seo_title'] ?? null) : null,
            'meta' => is_array($j) ? ($j['meta_description'] ?? null) : null, 'headings' => $headings, 'intro' => $intro];
    }

    /** @param list<array<string,mixed>> $items @return list<array{url:?string,alt:string,role:string,prompt:string}> */
    private static function images(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $a = isset($i['asset_id']) ? MediaAsset::query()->find($i['asset_id']) : null;
            $url = null;
            try {
                $url = $a ? Storage::disk($a->disk ?: 'public')->url((string) ($a->thumbnail_path ?: $a->file_path)) : null;
            } catch (\Throwable) {
            }
            $out[] = ['url' => $url, 'alt' => (string) ($a?->alt_text ?? ''), 'role' => (string) ($i['role'] ?? ''), 'prompt' => (string) ($i['prompt'] ?? '')];
        }

        return $out;
    }
}
