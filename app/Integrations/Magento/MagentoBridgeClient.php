<?php

namespace App\Integrations\Magento;

use App\Contracts\PublisherInterface;
use App\Integrations\Magento\OAuth\OAuth1Signer;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\Website;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Log;

class MagentoBridgeClient implements PublisherInterface
{
    protected Client $httpClient;

    public function __construct(?Client $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
    }

    public function testConnection(Website $website): bool
    {
        $baseUrl = rtrim($website->base_url, '/');
        $store = $website->store_scope ?: 'default';
        $endpoint = "{$baseUrl}/rest/{$store}/V1/store/websites";

        try {
            $headers = $this->getAuthHeaders('GET', $endpoint, $website->encrypted_credentials ?? []);
            $response = $this->httpClient->get($endpoint, [
                'headers' => $headers,
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Throwable $e) {
            Log::warning("Kiểm tra kết nối Magento 2 thất bại cho {$website->name}: " . $e->getMessage());
            return false;
        }
    }

    public function uploadMedia(Website $website, string $filePath, string $altText = ''): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File ảnh không tồn tại: {$filePath}");
        }

        $fileName = basename($filePath);
        $fileContent = base64_encode(file_get_contents($filePath));

        $baseUrl = rtrim($website->base_url, '/');
        $store = $website->store_scope ?: 'default';
        $endpoint = "{$baseUrl}/rest/{$store}/V1/company-blog/media";

        $payload = [
            'fileName' => $fileName,
            'base64Content' => $fileContent,
            'altText' => $altText,
        ];

        $headers = $this->getAuthHeaders('POST', $endpoint, $website->encrypted_credentials ?? []);

        $response = $this->httpClient->post($endpoint, [
            'headers' => $headers,
            'json' => $payload,
        ]);

        $body = json_decode((string) $response->getBody(), true);
        return [
            'id' => $body['id'] ?? $fileName,
            'source_url' => $body['url'] ?? '',
            'file' => $body['file'] ?? '',
        ];
    }

    public function createDraft(Website $website, ArticleVersion $version, ?string $publicationKey = null): array
    {
        $baseUrl = rtrim($website->base_url, '/');
        $store = $website->store_scope ?: 'default';
        $endpoint = "{$baseUrl}/rest/{$store}/V1/company-blog/posts";

        $publicationKey = $publicationKey ?? "magento-{$website->id}-art{$version->article_id}-v{$version->version}";

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

        $headers = $this->getAuthHeaders('POST', $endpoint, $website->encrypted_credentials ?? []);

        $response = $this->httpClient->post($endpoint, [
            'headers' => $headers,
            'json' => $postPayload,
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $postId = $body['post_id'] ?? null;

        return [
            'remote_id' => (string) $postId,
            'preview_url' => "{$baseUrl}/blog/post/view/id/{$postId}",
            'publication_key' => $publicationKey,
        ];
    }

    public function publish(Publication $publication): array
    {
        $website = $publication->website;
        $baseUrl = rtrim($website->base_url, '/');
        $store = $website->store_scope ?: 'default';

        // 1. Nếu chưa có remote_id, trước tiên tạo draft hoặc tra cứu theo publication_key
        $remoteId = $publication->cms_post_id;
        if (empty($remoteId)) {
            $existing = $this->getByPublicationKey($website, $publication->publication_key);
            if ($existing) {
                $remoteId = $existing['remote_id'];
            } else {
                $draft = $this->createDraft($website, $publication->articleVersion, $publication->publication_key);
                $remoteId = $draft['remote_id'];
            }
        }

        // 2. Kích hoạt publish bài viết
        $endpoint = "{$baseUrl}/rest/{$store}/V1/company-blog/posts/{$remoteId}/publish";
        $headers = $this->getAuthHeaders('POST', $endpoint, $website->encrypted_credentials ?? []);

        $response = $this->httpClient->post($endpoint, [
            'headers' => $headers,
        ]);

        $body = json_decode((string) $response->getBody(), true);
        $urlKey = $body['url_key'] ?? $publication->articleVersion->slug;
        $publishedUrl = "{$baseUrl}/blog/post/{$urlKey}";

        return [
            'remote_id' => (string) $remoteId,
            'published_url' => $publishedUrl,
        ];
    }

    public function getByPublicationKey(Website $website, string $publicationKey): ?array
    {
        $baseUrl = rtrim($website->base_url, '/');
        $store = $website->store_scope ?: 'default';
        $endpoint = "{$baseUrl}/rest/{$store}/V1/company-blog/posts/by-key/{$publicationKey}";

        try {
            $headers = $this->getAuthHeaders('GET', $endpoint, $website->encrypted_credentials ?? []);
            $response = $this->httpClient->get($endpoint, [
                'headers' => $headers,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            if (!empty($body['post_id'])) {
                return [
                    'remote_id' => (string) $body['post_id'],
                    'status' => $body['status'] ?? 0,
                    'title' => $body['title'] ?? '',
                ];
            }
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }

        return null;
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
