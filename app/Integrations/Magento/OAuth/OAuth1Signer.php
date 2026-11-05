<?php

namespace App\Integrations\Magento\OAuth;

class OAuth1Signer
{
    /**
     * Tạo chuỗi Authorization Header chuẩn OAuth 1.0a cho Magento 2 REST API
     */
    public static function buildAuthHeader(
        string $method,
        string $url,
        array $credentials,
        array $queryParams = [],
        string $signatureMethod = 'HMAC-SHA256'
    ): string {
        $consumerKey = $credentials['consumer_key'] ?? '';
        $consumerSecret = $credentials['consumer_secret'] ?? '';
        $accessToken = $credentials['access_token'] ?? '';
        $tokenSecret = $credentials['access_token_secret'] ?? '';

        $oauthParams = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => $signatureMethod,
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $accessToken,
            'oauth_version' => '1.0',
        ];

        // Gộp toàn bộ tham số để ký (query params + oauth params)
        $allParams = array_merge($queryParams, $oauthParams);
        ksort($allParams);

        // Chuẩn hóa parameter string
        $paramPairs = [];
        foreach ($allParams as $k => $v) {
            $paramPairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $normalizedParams = implode('&', $paramPairs);

        // Chuẩn hóa base URL (loại bỏ query string)
        $urlParts = parse_url($url);
        $baseUrl = ($urlParts['scheme'] ?? 'http') . '://' . ($urlParts['host'] ?? '') . ($urlParts['path'] ?? '');

        // Tạo Signature Base String
        $baseString = strtoupper($method) . '&' . rawurlencode($baseUrl) . '&' . rawurlencode($normalizedParams);

        // Tạo Signing Key
        $signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);

        // Ký số
        if ($signatureMethod === 'HMAC-SHA256') {
            $signature = base64_encode(hash_hmac('sha256', $baseString, $signingKey, true));
        } else {
            $signature = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));
        }

        $oauthParams['oauth_signature'] = $signature;

        // Tạo chuỗi Authorization Header
        $headerParts = [];
        foreach ($oauthParams as $k => $v) {
            $headerParts[] = rawurlencode($k) . '="' . rawurlencode($v) . '"';
        }

        return 'OAuth ' . implode(', ', $headerParts);
    }
}
