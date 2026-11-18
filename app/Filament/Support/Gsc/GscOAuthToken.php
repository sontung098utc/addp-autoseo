<?php

namespace App\Filament\Support\Gsc;

use App\Models\GscConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Token truy cập Google lấy từ refresh token của tài khoản người dùng đã cấp quyền. Token cache tới lúc hết hạn. */
final class GscOAuthToken implements GscTokenProvider
{
    public function __construct(private readonly GscConnection $conn) {}

    public static function fromConfig(): ?self
    {
        if (! GscOAuth::configured()) {
            return null;
        }
        $c = GscConnection::active();

        return $c ? new self($c) : null;
    }

    public function kind(): string
    {
        return 'oauth';
    }

    public function label(): string
    {
        return 'tài khoản Google '.($this->conn->google_email ?: 'đã kết nối');
    }

    /** @throws GscFailed */
    public function get(): string
    {
        $key = 'gsc:oauth:token:'.$this->conn->id;
        if (is_string($t = Cache::get($key)) && $t !== '') {
            return $t;
        }
        try {
            $res = Http::asForm()->acceptJson()->timeout(30)->post((string) config('gsc.token_uri'), [
                'grant_type' => 'refresh_token', 'refresh_token' => $this->conn->refresh_token,
                'client_id' => config('gsc.oauth.client_id'), 'client_secret' => config('gsc.oauth.client_secret'),
            ]);
        } catch (\Throwable $e) {
            throw new GscFailed('Không kết nối được Google để lấy token: '.mb_substr($e->getMessage(), 0, 120));
        }
        $token = $res->successful() ? (string) ($res->json('access_token') ?? '') : '';
        if ($token === '') {
            if ($res->json('error') === 'invalid_grant') {
                // Người dùng đã gỡ quyền hoặc token bị Google thu hồi: không thử lại vô ích, yêu cầu kết nối lại.
                $this->conn->update(['revoked_at' => now(), 'last_error' => 'invalid_grant']);
                throw new GscFailed('Kết nối Google đã hết hạn hoặc bị thu hồi; hãy kết nối lại tại trang Search Console.');
            }
            throw new GscFailed('Google từ chối cấp token (HTTP '.$res->status().'): '.mb_substr((string) ($res->json('error_description') ?? $res->json('error') ?? 'không rõ'), 0, 160));
        }
        Cache::put($key, $token, now()->addSeconds(max(60, (int) ($res->json('expires_in') ?? 3600) - 300)));

        return $token;
    }
}
