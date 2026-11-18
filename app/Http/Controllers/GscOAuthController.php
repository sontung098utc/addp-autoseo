<?php

namespace App\Http\Controllers;

use App\Filament\Pages\SearchConsolePage;
use App\Filament\Resources\WebsiteResource;
use App\Filament\Support\Gsc\GscFailed;
use App\Filament\Support\Gsc\GscOAuth;
use App\Models\GscConnection;
use App\Models\Website;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Kết nối Search Console bằng tài khoản Google (OAuth chỉ đọc). Chỉ người duyệt đang hoạt động; state chống giả mạo yêu cầu (CSRF). */
final class GscOAuthController
{
    private string $returnTo = '';

    private const SESSION_KEY = 'gsc_oauth_state';

    private const RETURN_KEY = 'gsc_oauth_return';

    public function start(Request $request): RedirectResponse
    {
        abort_unless(SearchConsolePage::canAccess(), 403);
        if (! GscOAuth::configured()) {
            Notification::make()->title('Chưa cấu hình OAuth')->body('Thiếu GSC_OAUTH_CLIENT_ID hoặc GSC_OAUTH_CLIENT_SECRET.')->danger()->send();

            return redirect(SearchConsolePage::getUrl());
        }
        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, $state);
        // Bắt đầu từ trang sửa Website (chỉ admin mở được): sau khi cấp quyền quay về đúng trang đó, không nhận địa chỉ tùy ý từ yêu cầu.
        $request->session()->forget(self::RETURN_KEY);
        $site = ctype_digit((string) $request->query('website')) ? Website::find((int) $request->query('website')) : null;
        if ($site && Auth::user()?->isAdmin()) {
            $request->session()->put(self::RETURN_KEY, WebsiteResource::getUrl('edit', ['record' => $site]));
        }

        return redirect()->away(GscOAuth::authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(SearchConsolePage::canAccess(), 403);
        $expected = (string) $request->session()->pull(self::SESSION_KEY, '');
        $this->returnTo = (string) $request->session()->pull(self::RETURN_KEY, '');
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->back('Yêu cầu kết nối không hợp lệ hoặc đã hết hạn', 'Hãy bấm "Kết nối Google Search Console" lại từ đầu.', false);
        }
        if ($request->query('error') !== null || ! is_string($code = $request->query('code')) || $code === '') {
            return $this->back('Chưa kết nối Google', 'Bạn đã từ chối cấp quyền hoặc Google không trả mã đăng nhập.', false);
        }
        try {
            $t = GscOAuth::exchange($code);
        } catch (GscFailed $e) {
            return $this->back('Không kết nối được Google', $e->getMessage(), false);
        }
        GscConnection::whereNull('revoked_at')->update(['revoked_at' => now()]);
        GscConnection::create(['google_email' => $t['email'], 'refresh_token' => $t['refresh_token'], 'connected_by' => Auth::id()]);

        return $this->back('Đã kết nối Google Search Console', ($t['email'] ?? 'Tài khoản Google').' đã cấp quyền chỉ đọc.', true);
    }

    private function back(string $title, string $body, bool $ok): RedirectResponse
    {
        $n = Notification::make()->title($title)->body($body);
        $ok ? $n->success()->send() : $n->danger()->send();

        return redirect($this->returnTo !== '' ? $this->returnTo : SearchConsolePage::getUrl());
    }
}
