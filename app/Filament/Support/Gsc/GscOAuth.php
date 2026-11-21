<?php

namespace App\Filament\Support\Gsc;

use App\Models\GscConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Luồng OAuth 2.0 (web server) của Google cho Search Console, chỉ đọc. Client id/secret do người dùng tự tạo trong Google Cloud
 * và đặt qua cấu hình; không ghi log, không đưa vào thông báo lỗi.
 */
final class GscOAuth
{
    public static function configured(): bool
    {
        return (string) config('gsc.oauth.client_id') !== '' && (string) config('gsc.oauth.client_secret') !== '';
    }

    /** Địa chỉ gọi lại: ưu tiên GSC_OAUTH_REDIRECT_URI (phải trùng khớp từng ký tự với địa chỉ khai trong Google Cloud). */
    public static function redirectUri(): string
    {
        return (string) config('gsc.oauth.redirect_uri') ?: route('gsc.oauth.callback');
    }

    public static function authorizeUrl(string $state): string
    {
        return (string) config('gsc.oauth.auth_uri').'?'.http_build_query([
            'client_id' => config('gsc.oauth.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => (string) config('gsc.scope').' '.(string) config('gsc.oauth.email_scope'),
            'access_type' => 'offline', // cần refresh token để chạy ngầm
            'prompt' => 'consent',      // buộc Google cấp lại refresh token khi kết nối lại
            'state' => $state,
        ]);
    }

    /** @return array{refresh_token:string,access_token:string,email:?string} @throws GscFailed */
    public static function exchange(string $code): array
    {
        try {
            $res = Http::asForm()->acceptJson()->timeout(30)->post((string) config('gsc.token_uri'), [
                'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri(),
                'client_id' => config('gsc.oauth.client_id'), 'client_secret' => config('gsc.oauth.client_secret'),
            ]);
        } catch (\Throwable $e) {
            throw new GscFailed('Không kết nối được Google để đổi mã đăng nhập: '.mb_substr($e->getMessage(), 0, 120));
        }
        $refresh = $res->successful() ? (string) ($res->json('refresh_token') ?? '') : '';
        $access = $res->successful() ? (string) ($res->json('access_token') ?? '') : '';
        if ($refresh === '' || $access === '') {
            $why = $res->successful()
                ? 'Google không cấp refresh token (hãy gỡ quyền của ứng dụng tại myaccount.google.com/permissions rồi kết nối lại).'
                : 'Google từ chối (HTTP '.$res->status().'): '.mb_substr((string) ($res->json('error_description') ?? $res->json('error') ?? 'không rõ'), 0, 160);
            throw new GscFailed($why);
        }

        return ['refresh_token' => $refresh, 'access_token' => $access, 'email' => self::email($access)];
    }

    private static function email(string $accessToken): ?string
    {
        try {
            $e = Http::withToken($accessToken)->acceptJson()->timeout(15)->get((string) config('gsc.oauth.userinfo_uri'))->json('email');
        } catch (\Throwable) {
            return null;
        }

        return is_string($e) && $e !== '' ? mb_substr($e, 0, 190) : null;
    }

    /** Thu hồi kết nối đang dùng: báo Google thu hồi token (nếu được) rồi đánh dấu đã thu hồi. Trả về true nếu có kết nối để thu hồi. */
    public static function disconnect(): bool
    {
        $conn = GscConnection::active();
        if (! $conn) {
            return false;
        }
        try {
            Http::asForm()->timeout(15)->post((string) config('gsc.oauth.revoke_uri'), ['token' => $conn->refresh_token]);
        } catch (\Throwable) {
            // Không chặn việc thu hồi phía hệ thống khi Google không phản hồi.
        }
        $conn->update(['revoked_at' => now()]);
        Cache::forget('gsc:oauth:token:'.$conn->id);

        return true;
    }
}
