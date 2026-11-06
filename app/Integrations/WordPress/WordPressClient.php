<?php

namespace App\Integrations\WordPress;

use App\Integrations\WordPress\Exceptions\WordPressAmbiguousException;
use App\Integrations\WordPress\Exceptions\WordPressErrorCode as E;
use App\Integrations\WordPress\Exceptions\WordPressException;
use App\Models\Website;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Tầng HTTP của connector WordPress. Không bao giờ log/đưa vào exception: header Authorization,
 * credential, query string. Chỉ HTTPS, kiểm SSRF, không theo redirect, giới hạn kích thước phản hồi.
 */
class WordPressClient
{
    public const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

    /** errno cURL xảy ra TRƯỚC khi yêu cầu được gửi (DNS, refused, TLS/cert). */
    protected const PRE_SEND_ERRNO = [6, 7, 35, 51, 58, 60];

    protected Client $http;

    /** @var array<string,bool> */
    protected array $checkedBase = [];

    public function __construct(?Client $http = null, protected ?UrlSafetyService $urlSafety = null)
    {
        $this->http = $http ?? new Client();
        $this->urlSafety ??= new UrlSafetyService();
    }

    /**
     * @return array{status:int, json:mixed, headers:array}
     * @throws WordPressException|WordPressAmbiguousException
     */
    public function request(Website $website, string $method, string $route, array $options = [], bool $mutating = false): array
    {
        $base = $this->safeBaseUrl($website);
        [$user, $pass] = $this->credentials($website);
        $path = '/wp-json' . $route; // chỉ path được đưa vào thông điệp lỗi, không bao giờ có query

        $options += [
            'timeout' => 30,
            'connect_timeout' => 10,
        ];
        $options['http_errors'] = false;
        $options['allow_redirects'] = false;
        $options['auth'] = [$user, $pass];
        $options['headers'] = ($options['headers'] ?? []) + [
            'User-Agent' => 'addp-autoseo/1.0 (+WordPress-Connector)',
            'Accept' => 'application/json',
        ];
        $options['on_headers'] = function (mixed $r): void {
            if (! $r instanceof ResponseInterface) {
                return; // Guzzle MockHandler có thể truyền Throwable vào đây
            }
            $len = $r->getHeaderLine('Content-Length');
            if ($len !== '' && (int) $len > self::MAX_RESPONSE_BYTES) {
                throw new \RuntimeException('response too large');
            }
        };

        try {
            $response = $this->http->request($method, $base . $path, $options);
        } catch (ConnectException $e) {
            throw $this->mapConnectException($e, $mutating, $method, $path);
        } catch (GuzzleException|Throwable $e) {
            if ($e instanceof WordPressException || $e instanceof WordPressAmbiguousException) {
                throw $e;
            }
            $tooLarge = str_contains($e->getMessage(), 'response too large');
            $detail = "{$method} {$path}" . ($tooLarge ? ', phản hồi quá lớn' : '');
            if ($tooLarge) {
                throw $this->fail(E::ERR06, $mutating, $detail);
            }
            throw $this->fail(E::ERR01, $mutating, $detail);
        }

        return $this->handleResponse($response, $method, $path, $mutating);
    }

    protected function handleResponse(ResponseInterface $response, string $method, string $path, bool $mutating): array
    {
        $status = $response->getStatusCode();
        $detail = "{$method} {$path}, HTTP {$status}";

        if ($status === 401 || $status === 403) {
            throw new WordPressException(E::ERR02, $detail);
        }
        if ($status === 404 || ($status >= 300 && $status < 400)) {
            throw new WordPressException(E::ERR03, $detail);
        }
        if ($status === 429) {
            throw new WordPressException(E::ERR04, $detail);
        }
        if ($status >= 500) {
            throw $this->fail(E::ERR05, $mutating, $detail);
        }
        if ($status >= 400) {
            throw new WordPressException(E::ERR06, $detail);
        }

        $size = $response->getBody()->getSize();
        if ($size !== null && $size > self::MAX_RESPONSE_BYTES) {
            throw $this->fail(E::ERR06, $mutating, $detail . ', phản hồi quá lớn');
        }
        $body = (string) $response->getBody();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw $this->fail(E::ERR06, $mutating, $detail . ', phản hồi quá lớn');
        }

        $json = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($json)) {
            // Đã nhận 2xx cho yêu cầu ghi nhưng không đọc được kết quả => bài có thể đã tồn tại.
            throw $this->fail(E::ERR06, $mutating, $detail . ', JSON không hợp lệ');
        }

        return ['status' => $status, 'json' => $json, 'headers' => $response->getHeaders()];
    }

    protected function mapConnectException(ConnectException $e, bool $mutating, string $method, string $path): WordPressException|WordPressAmbiguousException
    {
        $ctx = $e->getHandlerContext();
        $errno = (int) ($ctx['errno'] ?? 0);
        $connectTime = (float) ($ctx['connect_time'] ?? 0);
        $detail = "{$method} {$path}";

        $preSend = $errno === 0
            || in_array($errno, self::PRE_SEND_ERRNO, true)
            || ($errno === 28 && $connectTime <= 0.0);

        if ($preSend) {
            return new WordPressException(E::ERR07, $detail);
        }

        // Timeout/đứt kết nối sau khi đã kết nối: yêu cầu có thể đã tới WordPress.
        return $this->fail(E::ERR01, $mutating, $detail);
    }

    /** Lỗi mơ hồ chỉ khi là yêu cầu ghi; đọc thì chỉ là lỗi thường. */
    protected function fail(string $code, bool $mutating, string $detail): WordPressException|WordPressAmbiguousException
    {
        return $mutating
            ? new WordPressAmbiguousException($code, $detail)
            : new WordPressException($code, $detail);
    }

    protected function safeBaseUrl(Website $website): string
    {
        $url = trim((string) $website->base_url);
        $parts = parse_url($url);
        if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw new WordPressException(E::ERR03, 'chỉ chấp nhận HTTPS, không kèm user:pass');
        }
        $base = rtrim($url, '/');
        if (! isset($this->checkedBase[$base])) {
            try {
                $this->urlSafety->validateUrl($base);
            } catch (InvalidArgumentException) {
                throw new WordPressException(E::ERR03, 'host nội bộ hoặc không phân giải được');
            }
            $this->checkedBase[$base] = true;
        }

        return $base;
    }

    /** @return array{0:string,1:string} */
    protected function credentials(Website $website): array
    {
        $c = $website->encrypted_credentials ?? [];
        $user = (string) ($c['username'] ?? '');
        $pass = (string) ($c['application_password'] ?? '');
        if ($user === '' || $pass === '') {
            throw new WordPressException(E::ERR02, 'thiếu username hoặc application_password');
        }

        return [$user, $pass];
    }
}
