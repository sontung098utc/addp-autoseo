<?php

namespace App\Services\Images;

use App\Contracts\ImageGeneratorInterface;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\BrandProfile;
use App\Models\MediaAsset;
use App\Services\Agent\Refresh\HtmlSections;
use App\Services\Agent\Risk\RiskClassifier;
use App\Services\Brain\BrainContext;
use App\Services\Media\MediaService;
use Illuminate\Support\Str;

/**
 * Tạo ảnh cho một phiên bản bài. Luật (0 token): thư viện đã có ảnh bìa thì không tạo gì; không thì 1 ảnh bìa + tối đa N ảnh minh họa
 * (N = min(options.max_inline hoặc config agent.images.max_inline, số mục H2 dùng được, trần cứng 4 trừ ảnh bìa)).
 * Trần chi phí: 10% ngân sách phiên cho mọi ảnh của bài; chạm trần thì dừng và báo. Chạy lại: bài đã có ảnh AI thì bỏ qua trừ khi regenerate.
 * Ảnh chèn vào thân bài là <figure> do mã này dựng (alt/URL đã escape, qua MediaService nên đã kiểm MIME/kích thước), kèm chú thích "Ảnh minh họa do AI tạo".
 * Nguồn gốc ghi ở media_assets.metadata: ai_generated, prompt, model, role, cost_vnd... (không cần migration).
 */
final class ArticleImageService
{
    private const SKIP_HEADINGS = '/(cau hoi thuong gap|faq|ket luan|tong ket)/';

    public function __construct(private ?ImageGeneratorInterface $generator = null, private ?MediaService $media = null) {}

    /**
     * @param  array{regenerate?:bool, max_inline?:int, budget_vnd?:float, generator?:ImageGeneratorInterface}  $options
     * @return array{generated:int, skipped:?string, cost_vnd:float, items:list<array{asset_id:int,role:string,prompt:string}>, skip_code?:string, errors?:list<string>, stopped?:string, generator_label?:string}
     */
    public function generateFor(Article $article, ArticleVersion $version, array $options = []): array
    {
        $out = ['generated' => 0, 'skipped' => null, 'cost_vnd' => 0.0, 'items' => []];
        $skip = fn (string $code, string $why) => array_merge($out, ['skipped' => $why, 'skip_code' => $code]);

        if (! config('agent.images.enabled', true)) {
            return $skip('disabled', 'Tính năng tự tạo ảnh đang tắt.');
        }
        $brand = BrandProfile::query()->where('website_id', $article->website_id)->first();
        if (! config('agent.images.medical', false) && $this->isHealth($article, $brand)) {
            return $skip('medical', 'Lĩnh vực sức khỏe: không tự tạo ảnh.');
        }
        $regenerate = (bool) ($options['regenerate'] ?? false);
        $existing = $version->mediaAssets()->get();
        $aiExisting = $existing->filter(fn (MediaAsset $a) => ($a->metadata['ai_generated'] ?? false) === true);
        $libraryCover = $existing->first(fn (MediaAsset $a) => (bool) $a->pivot->is_featured && ! (($a->metadata['ai_generated'] ?? false) === true));
        if ($libraryCover) {
            return $skip('library_cover', 'Đã có ảnh bìa chọn từ thư viện của website, không tạo ảnh mới.');
        }
        if ($aiExisting->isNotEmpty() && ! $regenerate) {
            return $skip('already_generated', 'Phiên bản này đã có ảnh AI tạo (dùng tạo lại để làm mới).');
        }

        $generator = $options['generator'] ?? $this->generator;
        if ($generator === null) {
            try {
                $r = ImageGeneratorResolver::resolve();
                $generator = $r['generator'];
                $out['generator_label'] = $r['label'];
            } catch (ImageGeneratorUnavailable $e) {
                return $skip('no_provider', $e->getMessage());
            }
        }

        $health = $this->isHealth($article, $brand);
        $plan = $this->plan($version, (int) ($options['max_inline'] ?? config('agent.images.max_inline', 2)));
        $budget = (float) ($options['budget_vnd'] ?? config('agent.images.default_budget_vnd', 50000.0));
        $cap = $budget * (float) config('agent.images.budget_share', 0.10);

        if ($regenerate && $aiExisting->isNotEmpty()) {
            $version->mediaAssets()->detach($aiExisting->pluck('id')->all());
            $version->update(['html_content' => self::stripAiFigures((string) $version->html_content)]);
            $version->refresh();
        }

        $w = max(320, (int) config('agent.images.max_width', 1200));
        $media = $this->media ?? app()->makeWith(MediaService::class, ['imageOptimizer' => new \App\Services\Media\ImageOptimizer($w, $w, max(40, min(95, (int) config('agent.images.quality', 76))))]);
        $inlineHtml = [];
        $position = (int) ($version->mediaAssets()->max('position') ?? -1) + 1;
        foreach ($plan as $slot) {
            $est = $generator->estimatedCostVnd();
            if ($cap + 1e-9 < $out['cost_vnd'] + $est) {
                $out['stopped'] = 'cost_cap';
                $out['skipped'] = $out['generated'] === 0 ? 'Chạm trần chi phí ảnh ('.self::money($cap).' = '.(int) (config('agent.images.budget_share', 0.10) * 100).'% ngân sách phiên) nên không tạo ảnh.' : null;
                $out['skip_code'] = 'cost_cap';
                break;
            }
            $prompt = ImagePromptBuilder::build($slot['role'], (string) $article->primary_keyword, $slot['heading'], $brand, $health);
            try {
                $res = $generator->generate($prompt, ['aspect' => $slot['role'] === 'featured' ? '16:9' : '4:3', 'role' => $slot['role']]);
                $asset = $this->store($media, $article, $version, $res, $slot);
            } catch (\Throwable $e) {
                $out['errors'][] = ErrorMasker::mask($e->getMessage());
                $out['skip_code'] = $out['generated'] === 0 ? 'provider_error' : 'partial';
                $out['skipped'] = $out['generated'] === 0 ? 'Nhà cung cấp ảnh lỗi: '.ErrorMasker::mask($e->getMessage()) : null;
                break;
            }
            $out['cost_vnd'] += (float) $res['cost_vnd'];
            $version->mediaAssets()->syncWithoutDetaching([$asset->id => ['is_featured' => $slot['role'] === 'featured', 'position' => $slot['role'] === 'featured' ? 0 : $position++, 'custom_alt' => $asset->alt_text]]);
            $out['items'][] = ['asset_id' => $asset->id, 'role' => $slot['role'], 'prompt' => $res['prompt']];
            $out['generated']++;
            if ($slot['role'] === 'inline' && $slot['section'] !== null) {
                $inlineHtml[$slot['section']] = $asset;
            }
        }
        if ($inlineHtml !== [] && config('agent.images.insert_inline', true)) {
            $version->update(['html_content' => self::insertFigures((string) $version->html_content, $inlineHtml)]);
        }
        if ($out['generated'] === 0 && $out['skipped'] === null && ! isset($out['stopped'])) {
            $out['skipped'] = 'Không có mục nào cần ảnh.';
            $out['skip_code'] = 'nothing_to_do';
        }

        return $out;
    }

    /**
     * Tạo ảnh theo KẾ HOẠCH (ImagePlanner) cho tới khi hết ô hoặc chạm trần chi phí. Không cần phiên bản bài: ảnh vào thư viện, việc gắn vào bài
     * làm ở assemble(). Mỗi ô lỗi được thử lại tối đa $retries lần (điều phối: chỉ làm lại ô lỗi, không làm lại cả bước).
     *
     * @param  list<array<string,mixed>>  $slots
     * @return array{items:array<string,array<string,mixed>>, generated:int, cost_vnd:float, stopped:?string, errors:list<string>}
     */
    public function generateSlots(Article $article, array $slots, ImageGeneratorInterface $generator, float $capVnd, int $retries = 1): array
    {
        $out = ['items' => [], 'generated' => 0, 'cost_vnd' => 0.0, 'stopped' => null, 'errors' => []];
        $w = max(320, (int) config('agent.images.max_width', 1200));
        $media = $this->media ?? app()->makeWith(MediaService::class, ['imageOptimizer' => new \App\Services\Media\ImageOptimizer($w, $w, max(40, min(95, (int) config('agent.images.quality', 76))))]);
        foreach ($slots as $slot) {
            $est = $generator->estimatedCostVnd();
            if ($capVnd + 1e-9 < $out['cost_vnd'] + $est) {
                $out['stopped'] = 'cost_cap';
                $out['items'][$slot['id']] = ['status' => 'skipped', 'error' => 'Chạm trần chi phí ảnh ('.self::money($capVnd).').', 'attempts' => 0];
                continue;
            }
            $item = ['status' => 'failed', 'attempts' => 0];
            for ($try = 0; $try <= $retries; $try++) {
                $item['attempts']++;
                try {
                    $res = $generator->generate((string) $slot['prompt'], ['aspect' => $slot['aspect'] ?? ($slot['role'] === 'featured' ? '16:9' : '4:3'), 'role' => $slot['role']]);
                    $asset = $this->store($media, $article, null, $res, $slot);
                    $out['cost_vnd'] += (float) $res['cost_vnd'];
                    $item = ['status' => 'ok', 'asset_id' => $asset->id, 'prompt' => $res['prompt'], 'cost_vnd' => (float) $res['cost_vnd'], 'attempts' => $item['attempts']];
                    break;
                } catch (\Throwable $e) {
                    $item['error'] = ErrorMasker::mask($e->getMessage());
                }
            }
            if ($item['status'] === 'ok') {
                $out['generated']++;
            } else {
                $out['errors'][] = (string) ($item['error'] ?? 'lỗi không rõ');
            }
            $out['items'][$slot['id']] = $item;
        }

        return $out;
    }

    /**
     * LẮP RÁP: gắn ảnh đã tạo vào phiên bản bài. Ảnh bìa gắn dấu is_featured (nếu bản chưa có ảnh bìa từ thư viện); ảnh trong bài chèn <figure>
     * sau đoạn mở đầu của mục H2 tương ứng. Mục được tìm theo tên (không dấu, không phân biệt hoa thường) rồi mới tới thứ tự H2, vì bài viết có
     * thể đổi tên mục so với dàn ý. Ảnh không tìm được mục thì KHÔNG chèn và được báo rõ.
     *
     * @param  list<array<string,mixed>>  $slots
     * @param  array<string,array<string,mixed>>  $items  kết quả của generateSlots()['items']
     * @return array{attached:int, placements:list<array<string,mixed>>}
     */
    public function assemble(ArticleVersion $version, array $slots, array $items): array
    {
        $placements = [];
        $bySection = [];
        $sections = HtmlSections::split((string) $version->html_content)['sections'];
        $hasLibraryCover = $version->mediaAssets()->get()->contains(fn (MediaAsset $a) => (bool) $a->pivot->is_featured && ($a->metadata['ai_generated'] ?? false) !== true);
        $position = (int) ($version->mediaAssets()->max('position') ?? -1) + 1;
        $attached = 0;
        foreach ($slots as $slot) {
            $it = $items[$slot['id']] ?? null;
            if (! is_array($it) || ($it['status'] ?? '') !== 'ok') {
                $placements[] = ['slot' => $slot['id'], 'role' => $slot['role'], 'status' => 'missing', 'note' => (string) ($it['error'] ?? 'chưa có ảnh')];

                continue;
            }
            $asset = MediaAsset::query()->find($it['asset_id']);
            if (! $asset) {
                $placements[] = ['slot' => $slot['id'], 'role' => $slot['role'], 'status' => 'missing', 'note' => 'ảnh không còn trong thư viện'];

                continue;
            }
            if ($slot['role'] === 'featured') {
                if ($hasLibraryCover) {
                    $placements[] = ['slot' => $slot['id'], 'role' => 'featured', 'asset_id' => $asset->id, 'status' => 'unused', 'note' => 'bản đã có ảnh bìa chọn từ thư viện'];

                    continue;
                }
                $version->mediaAssets()->syncWithoutDetaching([$asset->id => ['is_featured' => true, 'position' => 0, 'custom_alt' => $asset->alt_text]]);
                $placements[] = ['slot' => $slot['id'], 'role' => 'featured', 'asset_id' => $asset->id, 'status' => 'featured', 'note' => 'ảnh bìa (ảnh đại diện), không nằm trong nội dung'];
                $attached++;

                continue;
            }
            $idx = $this->sectionIndex($sections, (string) $slot['section_heading'], (int) $slot['section_order']);
            $version->mediaAssets()->syncWithoutDetaching([$asset->id => ['is_featured' => false, 'position' => $position++, 'custom_alt' => $asset->alt_text]]);
            $attached++;
            if ($idx === null) {
                $placements[] = ['slot' => $slot['id'], 'role' => 'inline', 'asset_id' => $asset->id, 'status' => 'unplaced', 'note' => 'không tìm thấy mục "'.$slot['section_heading'].'" trong bài; ảnh gắn vào bài nhưng chưa chèn vào nội dung'];

                continue;
            }
            $bySection[$idx] = $asset;
            $placements[] = ['slot' => $slot['id'], 'role' => 'inline', 'asset_id' => $asset->id, 'status' => 'inserted', 'note' => 'sau đoạn mở đầu của mục "'.($sections[$idx]['heading'] ?? '').'"'];
        }
        if ($bySection !== [] && config('agent.images.insert_inline', true)) {
            $version->update(['html_content' => self::insertFigures((string) $version->html_content, $bySection)]);
        }
        foreach ($placements as $p) {
            if (isset($p['asset_id'])) {
                $a = MediaAsset::query()->find($p['asset_id']);
                $a?->update(['metadata' => array_merge((array) $a->metadata, ['article_version_id' => $version->id])]);
            }
        }

        return ['attached' => $attached, 'placements' => $placements];
    }

    /** @param list<array{index:int, heading:string, html:string}> $sections */
    private function sectionIndex(array $sections, string $heading, int $order): ?int
    {
        $norm = fn (string $t) => Str::lower(Str::ascii(trim(preg_replace('/\s+/u', ' ', $t) ?? '')));
        $want = $norm($heading);
        foreach ($sections as $s) {
            if ($want !== '' && $norm($s['heading']) === $want) {
                return $s['index'];
            }
        }
        foreach ($sections as $s) {
            if ($want !== '' && ($norm($s['heading']) !== '' && (str_contains($norm($s['heading']), $want) || str_contains($want, $norm($s['heading']))))) {
                return $s['index'];
            }
        }

        return isset($sections[$order - 1]) ? $sections[$order - 1]['index'] : null;
    }

    /** @return list<array{role:string, heading:?string, section:?int}> */
    private function plan(ArticleVersion $version, int $maxInline): array
    {
        $hard = (int) config('agent.images.hard_cap', 4);
        $plan = [['role' => 'featured', 'heading' => null, 'section' => null]];
        $limit = max(0, min($maxInline, $hard - 1, 3));
        $usable = [];
        foreach (HtmlSections::split((string) $version->html_content)['sections'] as $s) {
            $h = trim($s['heading']);
            if ($h !== '' && ! preg_match(self::SKIP_HEADINGS, Str::lower(Str::ascii($h)))) {
                $usable[] = $s;
            }
        }
        // Rải đều các mục dùng được thay vì lấy dồn ở đầu bài.
        $n = min($limit, count($usable));
        for ($i = 0; $i < $n; $i++) {
            $s = $usable[(int) floor($i * count($usable) / $n)];
            $plan[] = ['role' => 'inline', 'heading' => $s['heading'], 'section' => $s['index']];
        }

        return $plan;
    }

    /** @param array{bytes:string,mime:string,model:string,cost_vnd:float,prompt:string,revised_prompt:?string} $res */
    private function store(MediaService $media, Article $article, ?ArticleVersion $version, array $res, array $slot): MediaAsset
    {
        if (ImageBytes::mime($res['bytes']) === null) {
            throw new ImageGenerationError('Dữ liệu ảnh không hợp lệ (không phải PNG/JPEG/WebP).');
        }
        $kw = ImagePromptBuilder::clean((string) $article->primary_keyword, null);
        $subject = $slot['role'] === 'featured' ? $kw : ImagePromptBuilder::clean((string) ($slot['heading'] ?? $slot['section_heading'] ?? ''), null);
        $alt = is_string($slot['alt'] ?? null) && $slot['alt'] !== '' ? $slot['alt'] : ($slot['role'] === 'featured' ? "Hình minh họa chủ đề {$subject}" : "Hình minh họa mục {$subject}");
        $dir = sys_get_temp_dir().'/ai_img_'.Str::random(10);
        mkdir($dir, 0700, true);
        $path = $dir.'/ai-'.Str::slug($subject ?: 'anh').'.'.(['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][ImageBytes::mime($res['bytes'])]);
        file_put_contents($path, $res['bytes']);
        try {
            $asset = $media->storeMedia($path, [
                'website_id' => $article->website_id, 'title' => Str::limit($alt, 120, ''), 'alt_text' => Str::limit($alt, 125, ''),
                'caption' => 'Ảnh minh họa do AI tạo', 'tags' => ['ai-generated'],
            ]);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
        $asset->update(['metadata' => array_merge((array) $asset->metadata, [
            'ai_generated' => true, 'prompt' => $res['prompt'], 'revised_prompt' => $res['revised_prompt'], 'model' => $res['model'],
            'role' => $slot['role'], 'cost_vnd' => $res['cost_vnd'], 'article_id' => $article->id, 'article_version_id' => $version?->id,
        ])]);

        return $asset;
    }

    /** @param array<int,MediaAsset> $bySection [chỉ số H2 => ảnh] */
    public static function insertFigures(string $html, array $bySection): string
    {
        $rep = [];
        foreach (HtmlSections::split($html)['sections'] as $s) {
            $asset = $bySection[$s['index']] ?? null;
            if (! $asset) {
                continue;
            }
            $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $fig = '<figure class="article-image-block ai-generated" data-ai-image="1"><img src="'.$e($asset->url).'" alt="'.$e((string) $asset->alt_text).'"'
                .($asset->width ? ' width="'.(int) $asset->width.'"' : '').($asset->height ? ' height="'.(int) $asset->height.'"' : '')
                .' loading="lazy" decoding="async" /><figcaption>Ảnh minh họa do AI tạo</figcaption></figure>';
            $pos = stripos($s['html'], '</p>');
            $rep[$s['index']] = $pos === false ? $s['html']."\n".$fig."\n" : substr($s['html'], 0, $pos + 4)."\n".$fig."\n".substr($s['html'], $pos + 4);
        }

        return HtmlSections::replace($html, $rep);
    }

    public static function stripAiFigures(string $html): string
    {
        return preg_replace('#\s*<figure[^>]*data-ai-image="1"[^>]*>.*?</figure>\s*#isu', "\n", $html) ?? $html;
    }

    public function isHealth(Article $article, ?BrandProfile $brand): bool
    {
        $assess = (new RiskClassifier)->assess((string) $article->primary_keyword);
        if (str_contains(implode(' ', $assess->reasons), 'sức khỏe')) {
            return true;
        }
        // Trí tuệ đã gắn (kể cả tự gắn) xếp bài vào hạng rủi ro cao (lĩnh vực sức khỏe...) thì cũng coi là sức khỏe: bộ từ khóa của RiskClassifier không phủ hết thuật ngữ.
        try {
            if (BrainContext::forArticle($article)->riskFloor() === 'high') {
                return true;
            }
        } catch (\Throwable) {
            // không có bảng Trí tuệ thì bỏ qua nhánh này
        }
        $f = Str::lower(Str::ascii((string) $brand?->business_field));

        return (bool) preg_match('/\b(y te|suc khoe|benh vien|phong kham|duoc pham|nha thuoc|dieu tri)\b/', $f);
    }

    private static function money(float $v): string
    {
        return number_format($v, 0, ',', '.').'đ';
    }
}
