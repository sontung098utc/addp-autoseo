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
use Illuminate\Support\Facades\Log;
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

        $urlKey = $body['url_key'] ?? $publication->articleVersion->slug;
        $publishedUrl = "{$baseUrl}/blog/post/{$urlKey}";

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
            ];
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
