<?php

namespace App\Filament\Support\Gsc;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Token truy cập Google cho service account (JWT bearer, RS256). Đọc file khóa do người dùng cấp qua config('gsc.service_account_json');
 * khóa riêng không được ghi log, không đưa vào thông báo lỗi. Token cache tới lúc hết hạn.
 */
final class GscAccessToken
{
    public function __construct(private readonly string $keyFile) {}

    public static function fromConfig(): ?self
    {
        $path = (string) config('gsc.service_account_json');

        return $path !== '' && is_file($path) && is_readable($path) ? new self($path) : null;
    }

    /** @throws GscFailed */
    public function get(): string
    {
        $key = 'gsc:token:'.md5($this->keyFile);
        if (is_string($t = Cache::get($key)) && $t !== '') {
            return $t;
        }
        $sa = json_decode((string) @file_get_contents($this->keyFile), true);
        if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new GscFailed('File service account không đúng định dạng (thiếu client_email hoặc private_key).');
        }
        $uri = (string) ($sa['token_uri'] ?? config('gsc.token_uri'));
        $now = time();
        $header = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64(json_encode(['iss' => $sa['client_email'], 'scope' => (string) config('gsc.scope'), 'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600]));
        $sig = '';
        if (! @openssl_sign($header.'.'.$claims, $sig, (string) $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new GscFailed('Không ký được JWT: khóa riêng trong file service account không hợp lệ.');
        }
        try {
            $res = Http::asForm()->acceptJson()->timeout(30)->post($uri, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $header.'.'.$claims.'.'.self::b64($sig)]);
        } catch (\Throwable $e) {
            throw new GscFailed('Không kết nối được Google để lấy token: '.mb_substr($e->getMessage(), 0, 120));
        }
        $token = $res->successful() ? (string) ($res->json('access_token') ?? '') : '';
        if ($token === '') {
            throw new GscFailed('Google từ chối cấp token (HTTP '.$res->status().'): '.mb_substr((string) ($res->json('error_description') ?? $res->json('error') ?? 'không rõ'), 0, 160));
        }
        Cache::put($key, $token, now()->addSeconds(max(60, (int) ($res->json('expires_in') ?? 3600) - 300)));

        return $token;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
