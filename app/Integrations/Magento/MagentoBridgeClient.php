<?php

namespace App\Integrations\Magento;

use App\Contracts\CmsError;
use App\Contracts\CmsErrorCode;
use App\Contracts\CmsErrorMapper;
use App\Contracts\PublisherInterface;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Integrations\Magento\OAuth\OAuth1Signer;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MagentoBridgeClient implements PublisherInterface
{
    protected Client $httpClient;

    protected ?UrlSafetyService $urlSafety;

    public function __construct(?Client $httpClient = null, ?UrlSafetyService $urlSafety = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
        $this->urlSafety = $urlSafety;
    }

    public function testConnection(Website $website): bool
    {
        $baseUrl = rtrim((string) $website->base_url, '/');
        // Trong Magento 2 REST API, endpoint /V1/store/websites là API toàn cục thuộc scope 'all'
        $endpoint = "{$baseUrl}/rest/all/V1/store/websites";
        $creds = $website->encrypted_credentials ?? [];

        try {
            $this->assertSafeUrl($endpoint);
            $headers = $this->getAuthHeaders('GET', $endpoint, $creds);
            $response = $this->httpClient->get($endpoint, [
                'headers' => $headers,
            ]);

            return $response->getStatusCode() === 200;
        } catch (Throwable $e) {
            // Fallback: Thử với Bearer token (chuẩn Magento 2 Integration Token)
            if (! empty($creds['access_token'])) {
                try {
                    $this->assertSafeUrl($endpoint);
                    $response = $this->httpClient->get($endpoint, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $creds['access_token'],
                            'Accept' => 'application/json',
                        ],
                    ]);

                    return $response->getStatusCode() === 200;
                } catch (Throwable $e2) {
                    Log::warning("Kiểm tra kết nối Magento 2 (Bearer) thất bại cho {$website->name}: " . ErrorMasker::mask($e2->getMessage()));
                }
            }
            Log::warning("Kiểm tra kết nối Magento 2 thất bại cho {$website->name}: " . ErrorMasker::mask($e->getMessage()));

            return false;
        }
    }

    public function uploadMedia(Website $website, string $filePath, string $altText = ''): array
    {
        if (! file_exists($filePath)) {
            throw new \InvalidArgumentException("File ảnh không tồn tại: {$filePath}");
        }

        $fileName = basename($filePath);
        $fileContent = base64_encode(file_get_contents($filePath));

        $baseUrl = rtrim((string) $website->base_url, '/');
        $store = $website->store_scope ?: 'default';
        $endpoint = "{$baseUrl}/rest/{$store}/V1/company-blog/media";

        $body = $this->sendJsonRequest('POST', $endpoint, $website->encrypted_credentials ?? [], [
            'json' => [
                'fileName' => $fileName,
                'base64Content' => $fileContent,
                'altText' => $altText,
            ],
        ], isWriteOperation: true);

        return [
            'id' => $body['id'] ?? $fileName,
            'source_url' => $body['url'] ?? '',
            'file' => $body['file'] ?? '',
        ];
    }

    public function createDraft(Website $website, ArticleVersion $version, ?string $publicationKey = null): array
    {
        $baseUrl = rtrim((string) $website->base_url, '/');
        $store = $website->store_scope ?: 'default';
        $endpoint = "{$baseUrl}/rest/{$store}/V1/company-blog/posts";

        // E9: Ưu tiên publication_key do hệ thống cấp; nếu chưa truyền thì dùng đúng quy ước ReviewWorkflow::publicationKey
        $publicationKey = ($publicationKey !== null && $publicationKey !== '')
            ? $publicationKey
            : ReviewWorkflow::publicationKey((int) $website->id, (int) $version->article_id, (int) $version->version);

        $postPayload = [
            'post' => [
                'publication_key' => $publicationKey,
                'title' => $version->title,
                'url_key' => $version->slug,
                'post_content' => $version->html_content,
                'short_description' => $version->meta_description,
                'store_ids' => [0],
                'status' => 0, // Nháp (Draft)
            ],
        ];
        // Ảnh đại diện (thumbnail ở trang danh sách blog): module Magento ghi vào featured_img; không có ảnh hoặc tải lỗi thì vẫn đăng bài.
        if (($image = $this->featuredImagePath($website, $version)) !== null) {
            $postPayload['post']['image'] = $image;
        }

        $body = $this->sendJsonRequest('POST', $endpoint, $website->encrypted_credentials ?? [], [
            'json' => $postPayload,
        ], isWriteOperation: true);

        $postId = $body['post_id'] ?? null;
        if ($postId === null || $postId === '') {
            throw CmsError::ambiguous(CmsErrorCode::ERR06, 'phản hồi tạo nháp Magento thiếu post_id');
        }

        return [
            'remote_id' => (string) $postId,
            'preview_url' => "{$baseUrl}/blog/post/view/id/{$postId}",
            'publication_key' => $publicationKey,
        ];
    }

    /**
     * Ảnh bìa của phiên bản (ảnh đánh dấu is_featured, không có thì ảnh đầu tiên) tải lên Magento qua module một lần cho mỗi website; đường dẫn media Magento trả về được nhớ
     * ở metadata.magento_media của ảnh nên nháp rồi đăng không tải trùng. Lỗi hoặc file mất: trả null và ghi log (ảnh đại diện chỉ là phần thêm).
     */
    protected function featuredImagePath(Website $website, ArticleVersion $version): ?string
    {
        try {
            /** @var MediaAsset|null $asset */
            $asset = $version->featured_media_asset;
            if ($asset === null) {
                return null;
            }
            $site = (string) $website->id;
            $known = (array) ($asset->metadata['magento_media'] ?? []);
            if (is_string($known[$site] ?? null) && $known[$site] !== '') {
                return $known[$site];
            }
            $disk = Storage::disk($asset->disk ?: 'public');
            if (! $disk->exists((string) $asset->file_path)) {
                Log::warning("[Magento] Ảnh #{$asset->id} không còn trên ổ lưu trữ; đăng không kèm ảnh đại diện.");

                return null;
            }
            $path = $disk->path((string) $asset->file_path);
            $tmp = null;
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'webp') {
                $tmp = $this->webpToJpeg($path); // Magento (GD2) không resize được WebP; null = không chuyển được thì bỏ ảnh bìa
                if ($tmp === null) {
                    Log::warning("[Magento] Ảnh #{$asset->id} là WebP và không chuyển được sang JPEG; đăng không kèm ảnh đại diện.");

                    return null;
                }
                $path = $tmp;
            }
            try {
                $up = $this->uploadMedia($website, $path, (string) $asset->alt_text);
            } finally {
                if ($tmp !== null) {
                    @unlink($tmp);
                    @rmdir(dirname($tmp));
                }
            }
            $file = (string) ($up['file'] ?? '');
            if ($file === '') {
                return null;
            }
            $asset->update(['metadata' => array_merge((array) $asset->metadata, ['magento_media' => [$site => $file] + $known])]);

            return $file;
        } catch (Throwable $e) {
            Log::warning("[Magento] Không tải được ảnh đại diện lên website #{$website->id}: ".get_class($e).'; đăng không kèm ảnh đại diện.');

            return null;
        }
    }

    /** WebP -> JPEG (chất lượng 85, nền trắng cho ảnh trong suốt) vào file tạm cùng tên đổi đuôi .jpg. Không có GD webp hoặc ảnh hỏng: null. */
    protected function webpToJpeg(string $path): ?string
    {
        if (! function_exists('imagecreatefromwebp') || ! function_exists('imagejpeg')) {
            return null;
        }
        $im = @imagecreatefromwebp($path);
        if ($im === false) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
        $dir = sys_get_temp_dir().'/magento-cover-'.bin2hex(random_bytes(6));
        if (! @mkdir($dir, 0700) && ! is_dir($dir)) {
            return null;
        }
        $out = $dir.'/'.(preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($path, PATHINFO_FILENAME)) ?: 'cover').'.jpg';
        $ok = imagejpeg($canvas, $out, 85);
        if (! $ok) {
            @rmdir($dir);

            return null;
        }

        return $out;
    }

    public function publish(Publication $publication): array
    {
        $website = $publication->website;
        $baseUrl = rtrim((string) $website->base_url, '/');
        $store = $website->store_scope ?: 'default';

        // 1. Nếu chưa có remote_id, trước tiên tra cứu theo publication_key hoặc tạo draft với đúng key hệ thống cấp
        $remoteId = $publication->cms_post_id;
        if (empty($remoteId)) {
            $existing = $this->getByPublicationKey($website, (string) $publication->publication_key);
            if ($existing) {
                $remoteId = $existing['remote_id'];
            } else {
                $draft = $this->createDraft($website, $publication->articleVersion, $publication->publication_key);
                $remoteId = $draft['remote_id'];
            }
        }

        // 2. Kích hoạt publish bài viết
        $endpoint = "{$baseUrl}/rest/{$store}/V1/company-blog/posts/{$remoteId}/publish";
        $body = $this->sendJsonRequest('POST', $endpoint, $website->encrypted_credentials ?? [], [], isWriteOperation: true);

        // Link công khai: ưu tiên "url" mô-đun trả về (cùng tên miền), không có thì dựng theo nền tảng blog.
        // Mô-đun cũ chưa trả blog_driver thì mặc định Magefan ({base}/blog/{url_key}) vì ADDP dùng Magefan_Blog.
        $publishedUrl = MagentoBlogUrl::fromResponse($website, $body, (string) $publication->articleVersion->slug);

        return [
            'remote_id' => (string) $remoteId,
            'published_url' => $publishedUrl,
        ];
    }

    public function getByPublicationKey(Website $website, string $publicationKey): ?array
    {
        $baseUrl = rtrim((string) $website->base_url, '/');
        $store = $website->store_scope ?: 'default';
        $endpoint = "{$baseUrl}/rest/{$store}/V1/company-blog/posts/by-key/{$publicationKey}";

        try {
            $body = $this->sendJsonRequest('GET', $endpoint, $website->encrypted_credentials ?? [], [], isWriteOperation: false);
        } catch (CmsError $e) {
            if ($e->httpStatus() === 404 || $e->errorCode() === CmsErrorCode::ERR03 && $e->httpStatus() === 404) {
                return null;
            }
            throw $e;
        }

        if (! empty($body['post_id'])) {
            return [
                'remote_id' => (string) $body['post_id'],
                'status' => $body['status'] ?? 0,
                'title' => $body['title'] ?? '',
            ] + (! empty($body['url_key']) || ! empty($body['url'])
                ? ['published_url' => MagentoBlogUrl::fromResponse($website, $body, (string) ($body['url_key'] ?? ''))]
                : []);
        }

        return null;
    }

    /**
     * Gửi HTTP request kèm kiểm tra SSRF và map mọi ngoại lệ Guzzle sang CmsError chuẩn (E13).
     *
     * @throws CmsError
     */
    protected function sendJsonRequest(string $method, string $endpoint, array $credentials, array $options = [], bool $isWriteOperation = false): array
    {
        $this->assertSafeUrl($endpoint);

        $headers = $this->getAuthHeaders($method, $endpoint, $credentials);
        $options['headers'] = ($options['headers'] ?? []) + $headers;

        try {
            $response = $this->httpClient->request($method, $endpoint, $options);
        } catch (Throwable $e) {
            throw CmsErrorMapper::fromThrowable($e, $isWriteOperation, "{$method} " . parse_url($endpoint, PHP_URL_PATH));
        }

        $status = $response->getStatusCode();
        if ($status >= 400 || ($status >= 300 && $status < 400)) {
            throw CmsErrorMapper::fromHttpStatus($status, $isWriteOperation, "{$method} " . parse_url($endpoint, PHP_URL_PATH));
        }

        $body = json_decode((string) $response->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($body)) {
            throw $isWriteOperation
                ? CmsError::ambiguous(CmsErrorCode::ERR06, 'phản hồi Magento JSON không hợp lệ', $status)
                : CmsError::definitive(CmsErrorCode::ERR06, 'phản hồi Magento JSON không hợp lệ', retryable: false, httpStatus: $status);
        }

        return $body;
    }

    /**
     * Chặn SSRF cơ bản (loopback/private IP, scheme không hợp lệ, user:pass nhúng trong URL).
     *
     * @throws CmsError
     */
    protected function assertSafeUrl(string $url): void
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! $parts || ! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw CmsError::definitive(CmsErrorCode::ERR03, 'URL Magento không hợp lệ hoặc chứa user:pass');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw CmsError::definitive(CmsErrorCode::ERR03, "Từ chối truy cập host nội bộ: {$host}");
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPrivateOrReserved = ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($isPrivateOrReserved) {
                throw CmsError::definitive(CmsErrorCode::ERR03, "Từ chối truy cập IP nội bộ: {$host}");
            }
        }

        if ($this->urlSafety !== null) {
            try {
                $this->urlSafety->validateUrl($url);
            } catch (\InvalidArgumentException $e) {
                throw CmsError::definitive(CmsErrorCode::ERR03, $e->getMessage(), previous: $e);
            }
        }
    }

    protected function getAuthHeaders(string $method, string $url, array $credentials): array
    {
        $authHeader = OAuth1Signer::buildAuthHeader($method, $url, $credentials);

        return [
            'Authorization' => $authHeader,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
