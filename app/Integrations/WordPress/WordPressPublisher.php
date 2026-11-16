<?php

namespace App\Integrations\WordPress;

use App\Contracts\PublisherInterface;
use App\Integrations\WordPress\Exceptions\WordPressAmbiguousException;
use App\Integrations\WordPress\Exceptions\WordPressErrorCode as E;
use App\Integrations\WordPress\Exceptions\WordPressException;
use App\Integrations\WordPress\Seo\NullAdapter;
use App\Integrations\WordPress\Seo\RankMathAdapter;
use App\Integrations\WordPress\Seo\SeoMetaAdapter;
use App\Integrations\WordPress\Seo\YoastAdapter;
use App\Integrations\WordPress\Taxonomy\WordPressTaxonomyService;
use App\Models\ArticleVersion;
use App\Models\MediaAsset;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Content\HtmlSanitizer;
use App\Services\Publishing\DraftCapable;
use App\Services\Media\MediaSecurityValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Connector WordPress (REST API + Application Password).
 *
 * DẤU NHẬN DẠNG IDEMPOTENCY: WordPress chuẩn không cho ghi post meta tùy ý qua REST nếu chưa
 * register_post_meta, nên key được nhúng vào cuối content dưới dạng comment HTML ẩn
 * `<!-- autoseo:publication_key=KEY -->` và tra cứu bằng GET /posts?search=KEY&status=any&context=edit,
 * rồi so khớp CHÍNH XÁC trong content.raw. GIỚI HẠN: tìm kiếm của WP dựa trên chỉ mục/từ khóa nên nếu
 * site thay search engine (Relevanssi, ElasticPress...) có thể bỏ sót; bài ở thùng rác không được thấy
 * (status=any bỏ trash); người dùng thiếu quyền unfiltered_html có thể bị kses lọc comment. Phương án
 * mạnh hơn (mu-plugin bridge expose lookup theo meta) là việc sau, KHÔNG nằm trong task này.
 */
class WordPressPublisher implements PublisherInterface, DraftCapable
{
    public function __construct(
        protected WordPressClient $client,
        protected WordPressTaxonomyService $taxonomy,
    ) {
    }

    public function testConnection(Website $website): bool
    {
        try {
            $res = $this->client->request($website, 'GET', '/wp/v2/users/me', ['query' => ['context' => 'edit']]);
        } catch (WordPressException|WordPressAmbiguousException $e) {
            Log::warning("[WordPress] Kiểm tra kết nối thất bại cho website #{$website->id}: {$e->getMessage()}");

            return false;
        }
        $caps = $res['json']['capabilities'] ?? [];

        return isset($res['json']['id']) && ! empty($caps['publish_posts']);
    }

    public function uploadMedia(Website $website, string $filePath, string $altText = ''): array
    {
        $info = (new MediaSecurityValidator())->validateFile($filePath); // ném InvalidArgumentException nếu MIME giả
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']];
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($filePath, PATHINFO_FILENAME)) ?: 'image';
        $name = trim($base, '-') . '.' . $ext;

        $handle = fopen($filePath, 'rb');
        try {
            $res = $this->client->request($website, 'POST', '/wp/v2/media', [
                'headers' => [
                    'Content-Type' => $info['mime'],
                    'Content-Disposition' => 'attachment; filename="' . $name . '"',
                ],
                'body' => $handle,
                'timeout' => 90,
            ], true);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $id = $res['json']['id'] ?? null;
        $src = $res['json']['source_url'] ?? null;
        if (! is_int($id) || ! is_string($src) || $src === '') {
            throw new WordPressAmbiguousException(E::ERR06, 'phản hồi tải media thiếu id/source_url');
        }

        $altUpdated = true;
        if ($altText !== '') {
            try {
                $this->client->request($website, 'POST', "/wp/v2/media/{$id}", ['json' => ['alt_text' => $altText]], true);
            } catch (WordPressException|WordPressAmbiguousException $e) {
                $altUpdated = false;
                Log::warning("[WordPress] Không cập nhật được alt cho media #{$id}: {$e->getMessage()}");
            }
        }

        return ['id' => $id, 'source_url' => $src, 'alt_updated' => $altUpdated];
    }

    public function createDraft(Website $website, ArticleVersion $version, ?string $publicationKey = null): array
    {
        $payload = $this->basePayload($version, 'draft', $website);

        if ($publicationKey !== null && $publicationKey !== '') {
            $key = $this->assertKey($publicationKey);
            $existing = $this->getByPublicationKey($website, $key);
            if ($existing !== null) {
                return [
                    'remote_id' => (string) $existing['remote_id'],
                    'preview_url' => (string) ($existing['published_url'] ?? ''),
                    'publication_key' => $key,
                ];
            }
            $payload['content'] .= "\n" . $this->marker($key);
        }

        if (($featured = $this->featuredMediaId($website, $version)) !== null) {
            $payload['featured_media'] = $featured;
        }

        $res = $this->client->request($website, 'POST', '/wp/v2/posts', ['json' => $payload], true);
        $id = $res['json']['id'] ?? null;
        if (! is_int($id)) {
            throw new WordPressAmbiguousException(E::ERR06, 'phản hồi tạo nháp thiếu id');
        }

        $result = [
            'remote_id' => (string) $id,
            'preview_url' => (string) ($res['json']['link'] ?? ''),
        ];
        if ($publicationKey !== null && $publicationKey !== '') {
            $result['publication_key'] = $publicationKey;
        }

        return $result;
    }

    public function publish(Publication $publication): array
    {
        $website = $publication->website;
        $version = $publication->articleVersion;
        $key = $this->assertKey((string) $publication->publication_key);

        // (1) Tra theo key TRƯỚC. Lỗi mạng/5xx ở bước này ném ra (không tạo bài khi không chắc).
        $existing = $this->getByPublicationKey($website, $key);
        if ($existing !== null) {
            if (($existing['status'] ?? '') !== 'publish') {
                $this->client->request($website, 'POST', "/wp/v2/posts/{$existing['remote_id']}", ['json' => ['status' => 'publish']], true);
                $existing = $this->readBack($website, (int) $existing['remote_id'], $version);
            }
            $existing['already_existed'] = true;

            return $existing;
        }

        // (2) Chắc chắn chưa có: tạo mới.
        $adapter = $this->adapterFor($website);
        $payload = $this->basePayload($version, 'publish', $website);
        $payload['content'] .= "\n" . $this->marker($key);
        $tax = $this->resolveTaxonomy($website, $payload);

        $meta = $adapter->metaFor($version);
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        if (($featured = $this->featuredMediaId($website, $version)) !== null) {
            $payload['featured_media'] = $featured;
        }

        // AG-S04 đăng từ nháp: nếu bản này đã có nháp CÒN HIỆU LỰC trên CMS thì cập nhật CHÍNH bài nháp đó
        // (kèm dấu publication_key) thay vì tạo bài mới. Không có cms_draft_id => đường cũ y nguyên.
        $draftId = $this->usableDraftId($version);
        $route = $draftId !== null ? "/wp/v2/posts/{$draftId}" : '/wp/v2/posts';

        try {
            try {
                $res = $this->client->request($website, 'POST', $route, ['json' => $payload], true);
            } catch (WordPressException $e) {
                // 400 do plugin SEO không đăng ký meta hoặc ảnh đại diện không còn trên WordPress: bài CHƯA được tạo/sửa,
                // thử lại một lần không có meta và không có ảnh đại diện.
                if (($meta !== [] || isset($payload['featured_media'])) && str_contains($e->getMessage(), 'HTTP 400')) {
                    Log::warning("[WordPress] Website #{$website->id} từ chối trường meta SEO hoặc ảnh đại diện; đăng không kèm các trường đó.");
                    unset($payload['meta'], $payload['featured_media']);
                    $res = $this->client->request($website, 'POST', $route, ['json' => $payload], true);
                } else {
                    throw $e;
                }
            }
        } catch (WordPressException $e) {
            // Nháp đã bị xóa trên CMS (404 rõ ràng, bước tra key ở (1) xác nhận chưa có bài mang key): đăng như bình thường.
            if ($draftId === null || ! str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
            Log::warning("[WordPress] Nháp #{$draftId} của website #{$website->id} không còn trên CMS; đăng bài mới theo publication_key.");
            $draftId = null;
            $res = $this->client->request($website, 'POST', '/wp/v2/posts', ['json' => $payload], true);
        }

        $id = $res['json']['id'] ?? null;
        if (! is_int($id) || ($draftId !== null && (string) $id !== $draftId)) {
            throw new WordPressAmbiguousException(E::ERR06, 'phản hồi tạo bài thiếu id hoặc không khớp bài nháp');
        }

        // (4) Đọc lại để xác minh. Nếu không đọc lại được, bài đã tồn tại => mơ hồ để đối soát.
        try {
            $result = $this->readBack($website, $id, $version, $adapter);
        } catch (WordPressException $e) {
            if ($e->errorCode() === E::ERR06) {
                throw $e; // bài tồn tại nhưng trạng thái sai: job failed, lần sau tra key sẽ thấy và sửa
            }
            throw new WordPressAmbiguousException($e->errorCode(), 'bài đã gửi nhưng không xác minh được');
        }
        $result['already_existed'] = false;
        $result['promoted_draft_id'] = $draftId;
        $result['taxonomy'] = $tax;
        if ($result['seo_meta_persisted'] === false) {
            Log::warning("[WordPress] Meta SEO không được lưu trên website #{$website->id}: plugin có thể chưa expose meta qua REST.");
        }

        return $result;
    }

    public function deleteDraft(Website $website, string $remoteId): void
    {
        if (! ctype_digit($remoteId)) {
            throw new WordPressException(E::ERR06, 'mã bài nháp không hợp lệ');
        }
        $res = $this->client->request($website, 'GET', "/wp/v2/posts/{$remoteId}", ['query' => ['context' => 'edit']]);
        // Chỉ xóa khi CHẮC CHẮN vẫn là nháp: không bao giờ động vào bài đã công khai hoặc đã lên lịch.
        if (! in_array($res['json']['status'] ?? '', ['draft', 'auto-draft', 'pending'], true)) {
            throw new WordPressException(E::ERR06, 'bài không còn ở trạng thái nháp nên không hủy');
        }
        // Không có force=true => vào thùng rác, khôi phục được.
        $this->client->request($website, 'DELETE', "/wp/v2/posts/{$remoteId}", [], true);
    }

    /** Mã nháp CÒN HIỆU LỰC của bản này (chỉ khi đã đẩy thành công và chưa hủy/đăng). */
    protected function usableDraftId(ArticleVersion $version): ?string
    {
        $id = (string) $version->cms_draft_id;

        return $version->cms_draft_status === 'pushed' && ctype_digit($id) && $id !== '' ? $id : null;
    }

    public function getByPublicationKey(Website $website, string $publicationKey): ?array
    {
        $key = $this->assertKey($publicationKey);
        // Lỗi mạng/5xx được ném nguyên, KHÔNG trả null (tránh đăng trùng).
        $res = $this->client->request($website, 'GET', '/wp/v2/posts', ['query' => [
            'search' => $key, 'status' => 'any', 'context' => 'edit', 'per_page' => 100,
        ]]);

        $pattern = '/<!--\s*autoseo:publication_key=' . preg_quote($key, '/') . '\s*-->/';
        $matches = [];
        foreach ($res['json'] as $post) {
            if (is_array($post) && isset($post['id']) && preg_match($pattern, (string) ($post['content']['raw'] ?? ''))) {
                $matches[] = $post;
            }
        }
        if (count($matches) > 1) {
            throw new WordPressException(E::ERR06, 'nhiều bài khớp cùng publication_key');
        }
        if ($matches === []) {
            return null;
        }
        $p = $matches[0];
        if (! is_int($p['id'])) {
            throw new WordPressException(E::ERR06, 'bài khớp key nhưng id không hợp lệ');
        }

        return [
            'remote_id' => (string) $p['id'],
            'published_url' => (string) ($p['link'] ?? ''),
            'preview_url' => (string) ($p['link'] ?? ''),
            'status' => (string) ($p['status'] ?? ''),
        ];
    }

    protected function readBack(Website $website, int $id, ArticleVersion $version, ?SeoMetaAdapter $adapter = null): array
    {
        $res = $this->client->request($website, 'GET', "/wp/v2/posts/{$id}", ['query' => ['context' => 'edit']]);
        $j = $res['json'];
        if (($j['id'] ?? null) !== $id || ($j['status'] ?? '') !== 'publish' || ! is_string($j['link'] ?? null) || $j['link'] === '') {
            throw new WordPressException(E::ERR06, 'đọc lại không khớp id/status/link');
        }
        $adapter ??= new NullAdapter();
        $remoteMeta = is_array($j['meta'] ?? null) ? $j['meta'] : [];

        return [
            'remote_id' => (string) $id,
            'published_url' => $j['link'],
            'seo_meta_persisted' => $adapter->isPersisted($remoteMeta, $version),
        ];
    }

    /**
     * Ảnh bìa của phiên bản (ảnh đánh dấu is_featured trong thư viện) -> ảnh đại diện (featured_media) của bài trên WordPress.
     * Không có ảnh bìa, file mất hoặc tải lỗi: trả null và ghi log, bài vẫn đăng bình thường (ảnh đại diện chỉ là phần thêm).
     */
    protected function featuredMediaId(Website $website, ArticleVersion $version): ?int
    {
        try {
            $asset = $version->mediaAssets()->wherePivot('is_featured', true)->first();

            return $asset === null ? null : ($this->wpMedia($website, $asset)['id'] ?? null);
        } catch (\Throwable $e) {
            Log::warning("[WordPress] Không tải được ảnh bìa lên website #{$website->id}: " . get_class($e) . '; đăng không kèm ảnh đại diện.');

            return null;
        }
    }

    /**
     * Ảnh của thư viện -> media trên WordPress. Tải lên một lần cho mỗi website; id và địa chỉ được nhớ ở metadata.wp_media của ảnh
     * nên nháp rồi đăng, hoặc ảnh vừa là ảnh bìa vừa nằm trong bài, không tải trùng. Lỗi thì trả null (nơi gọi quyết định bỏ qua).
     *
     * @return array{id:int,url:string}|null
     */
    protected function wpMedia(Website $website, MediaAsset $asset): ?array
    {
        $site = (string) $website->id;
        $known = (array) ($asset->metadata['wp_media'] ?? []);
        $hit = $known[$site] ?? null;
        if (is_array($hit) && is_int($hit['id'] ?? null) && is_string($hit['url'] ?? null) && $hit['url'] !== '') {
            return ['id' => $hit['id'], 'url' => $hit['url']];
        }
        $disk = Storage::disk($asset->disk ?: 'public');
        if (! $disk->exists((string) $asset->file_path)) {
            Log::warning("[WordPress] Ảnh #{$asset->id} không còn trên ổ lưu trữ; không tải lên WordPress.");

            return null;
        }
        $up = $this->uploadMedia($website, $disk->path((string) $asset->file_path), (string) $asset->alt_text);
        $entry = ['id' => $up['id'], 'url' => $up['source_url']];
        $asset->update(['metadata' => array_merge((array) $asset->metadata, ['wp_media' => [$site => $entry] + $known])]);

        return $entry;
    }

    /**
     * Ảnh trong thân bài trỏ về kho của ứng dụng (config filesystems.disks.public.url): tải lên WordPress và đổi src sang địa chỉ WordPress,
     * để bài hiển thị đúng trên website thật. Ảnh nào không tải được thì giữ nguyên src (không làm hỏng việc đăng).
     */
    protected function rewriteInlineImages(Website $website, string $html): string
    {
        $base = rtrim((string) config('filesystems.disks.public.url', ''), '/') . '/';
        if ($base === '/' || ! str_contains($html, '<img')) {
            return $html;
        }

        return (string) preg_replace_callback('#(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2#i', function (array $m) use ($website, $base): string {
            $src = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);
            if (! str_starts_with($src, $base)) {
                return $m[0];
            }
            $path = rawurldecode(strtok(substr($src, strlen($base)), '?#') ?: '');
            if (! str_starts_with($path, 'media/') || str_contains($path, '..')) {
                return $m[0];
            }
            try {
                $asset = MediaAsset::query()->where('file_path', $path)->first();
                $wp = $asset ? $this->wpMedia($website, $asset) : null;
            } catch (\Throwable $e) {
                Log::warning("[WordPress] Không tải được ảnh trong bài lên website #{$website->id}: " . get_class($e) . '; giữ nguyên địa chỉ ảnh.');
                $wp = null;
            }

            return $wp === null ? $m[0] : $m[1] . $m[2] . htmlspecialchars($wp['url'], ENT_QUOTES) . $m[2];
        }, $html);
    }

    protected function basePayload(ArticleVersion $version, string $status, ?Website $website = null): array
    {
        $content = HtmlSanitizer::clean($version->html_content);

        return [
            'status' => $status,
            'title' => (string) $version->title,
            'slug' => (string) $version->slug,
            'excerpt' => (string) $version->meta_description,
            'content' => $website !== null ? $this->rewriteInlineImages($website, $content) : $content,
        ];
    }

    protected function resolveTaxonomy(Website $website, array &$payload): array
    {
        $flags = $website->capability_flags ?? [];
        $allow = (bool) ($flags['allow_create_terms'] ?? false);
        $out = ['skipped' => []];
        foreach (['categories' => 'default_categories', 'tags' => 'default_tags'] as $type => $flag) {
            $names = $flags[$flag] ?? [];
            if (! is_array($names) || $names === []) {
                continue;
            }
            $r = $this->taxonomy->resolve($website, $type, $names, $allow);
            if ($r['ids'] !== []) {
                $payload[$type] = $r['ids'];
            }
            $out['skipped'] = array_merge($out['skipped'], $r['skipped']);
        }

        return $out;
    }

    protected function adapterFor(Website $website): SeoMetaAdapter
    {
        return match ($website->capability_flags['seo_plugin'] ?? 'none') {
            'rank_math' => new RankMathAdapter(),
            'yoast' => new YoastAdapter(),
            default => new NullAdapter(),
        };
    }

    protected function marker(string $key): string
    {
        return "<!-- autoseo:publication_key={$key} -->";
    }

    protected function assertKey(string $key): string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/', $key)) {
            throw new WordPressException(E::ERR06, 'publication_key sai định dạng');
        }

        return $key;
    }
}
