<?php

namespace Tests\Feature\Gsc;

use App\Filament\Support\Gsc\GoogleSearchConsoleSource;
use App\Filament\Support\Gsc\GscOAuth;
use App\Models\GscConnection;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Kiểm bằng HTTP giả lập: CHƯA kiểm chứng với Google thật. */
class GscOAuthTest extends TestCase
{
    use RefreshDatabase;

    private ?string $keyFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'gsc.service_account_json' => null, 'gsc.auth' => 'auto', 'gsc.property_type' => 'url', 'gsc.properties' => '',
            'gsc.oauth.client_id' => 'cid', 'gsc.oauth.client_secret' => 'csecret', 'gsc.oauth.redirect_uri' => 'http://localhost:8000/admin/search-console/oauth/callback',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->keyFile && is_file($this->keyFile)) {
            @unlink($this->keyFile);
        }
        parent::tearDown();
    }

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://addp.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function approver(): User
    {
        return User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
    }

    private function serviceAccountFile(): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($this->keyFile, json_encode(['client_email' => 'sa@p.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));

        return $this->keyFile;
    }

    private function gscRows(): array
    {
        return ['rows' => [['keys' => ['2026-11-10', 'https://addp.vn/a'], 'clicks' => 3, 'impressions' => 40, 'position' => 7.5]]];
    }

    public function test_authorize_url_asks_offline_read_only_with_state(): void
    {
        $u = GscOAuth::authorizeUrl('st123');
        parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
        $this->assertSame('cid', $q['client_id']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('st123', $q['state']);
        $this->assertStringContainsString('webmasters.readonly', $q['scope']);
        $this->assertStringNotContainsString('csecret', $u);
    }

    public function test_oauth_connection_is_used_when_no_service_account(): void
    {
        $site = $this->site();
        GscConnection::create(['google_email' => 'me@gmail.com', 'refresh_token' => 'rt-1']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            'www.googleapis.com/webmasters/*' => Http::response($this->gscRows()),
        ]);
        $src = GoogleSearchConsoleSource::forWebsite($site);
        $this->assertNotNull($src);
        $this->assertSame('oauth', $src->authKind());
        $this->assertSame('https://addp.vn/', $src->property());
        $rows = $src->rows('page', CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-10'));
        $this->assertCount(1, $rows);
        $this->assertNull($src->error());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'rt-1');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'searchAnalytics/query') && $r->hasHeader('Authorization', 'Bearer at-1'));
    }

    public function test_auto_prefers_service_account_over_oauth_and_mode_can_force_oauth(): void
    {
        $site = $this->site();
        GscConnection::create(['google_email' => 'me@gmail.com', 'refresh_token' => 'rt-1']);
        config(['gsc.service_account_json' => $this->serviceAccountFile()]);
        $this->assertSame('service_account', GoogleSearchConsoleSource::forWebsite($site)->authKind());
        config(['gsc.auth' => 'oauth']);
        $this->assertSame('oauth', GoogleSearchConsoleSource::forWebsite($site)->authKind());
        config(['gsc.auth' => 'service_account', 'gsc.service_account_json' => null]);
        $this->assertNull(GoogleSearchConsoleSource::forWebsite($site));
    }

    public function test_invalid_grant_marks_connection_revoked_and_returns_clear_error(): void
    {
        $site = $this->site();
        $c = GscConnection::create(['google_email' => 'me@gmail.com', 'refresh_token' => 'rt-1']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $src = GoogleSearchConsoleSource::forWebsite($site);
        $this->assertSame([], $src->rows('page', CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-10')));
        $this->assertStringContainsString('kết nối lại', (string) $src->error());
        $this->assertNotNull($c->fresh()->revoked_at);
        $this->assertNull(GoogleSearchConsoleSource::forWebsite($site), 'kết nối đã thu hồi không còn được dùng');
    }

    public function test_403_message_names_the_login_and_property(): void
    {
        $site = $this->site();
        GscConnection::create(['google_email' => 'me@gmail.com', 'refresh_token' => 'rt-1']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1', 'expires_in' => 3600]),
            'www.googleapis.com/webmasters/*' => Http::response([], 403),
        ]);
        $src = GoogleSearchConsoleSource::forWebsite($site);
        $src->rows('page', CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-10'));
        $this->assertStringContainsString('me@gmail.com', (string) $src->error());
        $this->assertStringContainsString('https://addp.vn/', (string) $src->error());
    }

    public function test_callback_rejects_wrong_state_and_stores_encrypted_token_on_success(): void
    {
        $this->actingAs($this->approver());
        $this->withSession(['gsc_oauth_state' => 'good'])->get('/admin/search-console/oauth/callback?state=bad&code=c')->assertRedirect();
        $this->assertSame(0, GscConnection::count());

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-9', 'refresh_token' => 'rt-secret-9', 'expires_in' => 3600]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'me@gmail.com']),
        ]);
        $this->withSession(['gsc_oauth_state' => 'good'])->get('/admin/search-console/oauth/callback?state=good&code=c')->assertRedirect();
        $c = GscConnection::active();
        $this->assertSame('me@gmail.com', $c->google_email);
        $this->assertSame('rt-secret-9', $c->refresh_token);
        $this->assertStringNotContainsString('rt-secret-9', (string) DB::table('gsc_connections')->value('refresh_token'), 'refresh token phải lưu mã hóa');
        $this->assertArrayNotHasKey('refresh_token', $c->toArray());
    }

    public function test_callback_without_refresh_token_does_not_connect(): void
    {
        $this->actingAs($this->approver());
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-9', 'expires_in' => 3600])]);
        $this->withSession(['gsc_oauth_state' => 'good'])->get('/admin/search-console/oauth/callback?state=good&code=c')->assertRedirect();
        $this->assertSame(0, GscConnection::count());
    }

    public function test_writer_cannot_start_or_finish_oauth(): void
    {
        $w = User::create(['name' => 'w', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);
        $this->actingAs($w)->get('/admin/search-console/oauth/start')->assertForbidden();
        $this->actingAs($w)->get('/admin/search-console/oauth/callback?state=x&code=c')->assertForbidden();
    }

    public function test_start_redirects_to_google_and_remembers_state(): void
    {
        $this->actingAs($this->approver());
        $res = $this->get('/admin/search-console/oauth/start');
        $res->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');
        $this->assertNotEmpty(session('gsc_oauth_state'));
    }

    public function test_disconnect_revokes_connection(): void
    {
        $c = GscConnection::create(['google_email' => 'me@gmail.com', 'refresh_token' => 'rt-1']);
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);
        $this->assertTrue(GscOAuth::disconnect());
        $this->assertNotNull($c->fresh()->revoked_at);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/revoke') && $r['token'] === 'rt-1');
        $this->assertFalse(GscOAuth::disconnect());
    }
}
