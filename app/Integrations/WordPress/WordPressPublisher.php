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
use App\Models\Publication;
use App\Models\Website;
use App\Services\Content\HtmlSanitizer;
use App\Services\Publishing\DraftCapable;
use App\Services\Media\MediaSecurityValidator;
use Illuminate\Support\Facades\Log;

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
        $payload = $this->basePayload($version, 'draft');

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
        $payload = $this->basePayload($version, 'publish');
        $payload['content'] .= "\n" . $this->marker($key);
        $tax = $this->resolveTaxonomy($website, $payload);

        $meta = $adapter->metaFor($version);
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        // AG-S04 đăng từ nháp: nếu bản này đã có nháp CÒN HIỆU LỰC trên CMS thì cập nhật CHÍNH bài nháp đó
        // (kèm dấu publication_key) thay vì tạo bài mới. Không có cms_draft_id => đường cũ y nguyên.
        $draftId = $this->usableDraftId($version);
        $route = $draftId !== null ? "/wp/v2/posts/{$draftId}" : '/wp/v2/posts';

        try {
            try {
                $res = $this->client->request($website, 'POST', $route, ['json' => $payload], true);
            } catch (WordPressException $e) {
                // 400 do plugin SEO không đăng ký meta: bài CHƯA được tạo/sửa, thử lại một lần không có meta.
                if ($meta !== [] && str_contains($e->getMessage(), 'HTTP 400')) {
                    Log::warning("[WordPress] Website #{$website->id} từ chối trường meta SEO; đăng không kèm meta.");
                    unset($payload['meta']);
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

    protected function basePayload(ArticleVersion $version, string $status): array
    {
        return [
            'status' => $status,
            'title' => (string) $version->title,
            'slug' => (string) $version->slug,
            'excerpt' => (string) $version->meta_description,
            'content' => HtmlSanitizer::clean($version->html_content),
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
