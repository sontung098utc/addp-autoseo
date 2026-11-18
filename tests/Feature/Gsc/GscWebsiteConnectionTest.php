<?php

namespace Tests\Feature\Gsc;

use App\Filament\Resources\WebsiteResource;
use App\Filament\Resources\WebsiteResource\Pages\EditWebsite;
use App\Filament\Support\Gsc\GoogleSearchConsoleSource;
use App\Filament\Support\Gsc\GscConnectionCheck;
use App\Models\GscConnection;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Mục Search Console ở trang Website. HTTP giả lập; CHƯA kiểm chứng với Google thật. */
class GscWebsiteConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['gsc.service_account_json' => null, 'gsc.auth' => 'auto', 'gsc.property_type' => 'domain', 'gsc.properties' => '',
            'gsc.oauth.client_id' => 'cid', 'gsc.oauth.client_secret' => 'csecret', 'gsc.oauth.redirect_uri' => 'http://localhost/cb']);
    }

    private function site(array $o = []): Website
    {
        return Website::create($o + ['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://addp.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_own_property_overrides_derived_one(): void
    {
        $this->assertSame('sc-domain:addp.vn', GoogleSearchConsoleSource::propertyFor($this->site()));
        $s = $this->site(['base_url' => 'https://b.vn', 'gsc_property' => 'https://b.vn/']);
        $this->assertSame('https://b.vn/', GoogleSearchConsoleSource::propertyFor($s));
    }

    public function test_describe_is_static_and_names_the_missing_piece(): void
    {
        $s = $this->site();
        Http::fake();
        $d = GscConnectionCheck::describe($s);
        $this->assertFalse($d['connected']);
        $this->assertTrue($d['oauth_ready']);
        Http::assertNothingSent();
        GscConnection::create(['google_email' => 'me@gmail.com', 'refresh_token' => 'rt']);
        $d = GscConnectionCheck::describe($s);
        $this->assertTrue($d['connected']);
        $this->assertSame('oauth', $d['kind']);
        $this->assertSame('sc-domain:addp.vn', $d['property']);
    }

    public function test_run_levels(): void
    {
        $s = $this->site();
        $this->assertSame('none', GscConnectionCheck::run($s)['level']);
        GscConnection::create(['google_email' => 'me@gmail.com', 'refresh_token' => 'rt']);
        $mode = 403;
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'www.googleapis.com/webmasters/*' => function () use (&$mode) {
                return match ($mode) {
                403 => Http::response([], 403),
                'empty' => Http::response(['rows' => []]),
                default => Http::response(['rows' => [['keys' => ['2026-11-10', 'https://addp.vn/a'], 'clicks' => 3, 'impressions' => 40, 'position' => 7.5]]]),
                };
            },
        ]);
        $r = GscConnectionCheck::run($s);
        $this->assertSame('error', $r['level']);
        $this->assertStringContainsString('403', $r['message']);
        $mode = 'empty';
        $this->assertSame('empty', GscConnectionCheck::run($s)['level']);
        $mode = 'ok';
        $ok = GscConnectionCheck::run($s);
        $this->assertSame('ok', $ok['level']);
        $this->assertSame(1, $ok['rows']);
    }

    public function test_property_format_rule(): void
    {
        foreach (['sc-domain:addp.vn', 'https://addp.vn/', 'http://a.vn/blog/'] as $good) {
            $this->assertSame(1, preg_match(WebsiteResource::GSC_PROPERTY_REGEX, $good), $good);
        }
        foreach (['khong-hop-le', 'https://addp.vn', 'sc-domain:', 'addp.vn'] as $bad) {
            $this->assertSame(0, preg_match(WebsiteResource::GSC_PROPERTY_REGEX, $bad), $bad);
        }
    }

    public function test_check_bypasses_cache_so_a_fixed_error_is_not_masked(): void
    {
        $s = $this->site();
        GscConnection::create(['google_email' => 'me@gmail.com', 'refresh_token' => 'rt']);
        $row = ['rows' => [['keys' => ['2026-11-10', 'https://addp.vn/a'], 'clicks' => 3, 'impressions' => 40, 'position' => 7.5]]];
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]), 'www.googleapis.com/webmasters/*' => Http::response($row)]);
        GscConnectionCheck::run($s);
        GscConnectionCheck::run($s);
        $this->assertCount(2, Http::recorded(fn ($req) => str_contains($req->url(), 'searchAnalytics')));
    }

    public function test_edit_page_shows_status_and_saves_property(): void
    {
        $s = $this->site();
        $this->actingAs($this->user('admin'));
        Livewire::test(EditWebsite::class, ['record' => $s->getKey()])
            ->assertSee('Search Console')->assertSee('Chưa kết nối')
            ->assertActionVisible('gscCheck')->assertActionVisible('gscConnect')->assertActionHidden('gscDisconnect')
            ->set('data.gsc_property', 'khong-hop-le')->call('save')->assertHasErrors(['data.gsc_property'])
            ->set('data.gsc_property', 'https://addp.vn/')->call('save')->assertHasNoErrors();
        $this->assertSame('https://addp.vn/', $s->fresh()->gsc_property);
    }

    public function test_edit_page_title_is_website_name_and_has_three_tabs(): void
    {
        $s = $this->site(['name' => 'Trang Dược ABC']);
        $this->actingAs($this->user('admin'));
        $c = Livewire::test(EditWebsite::class, ['record' => $s->getKey()]);
        $this->assertSame('Trang Dược ABC', (string) $c->instance()->getTitle());
        $c->assertSee('Thông tin chung')->assertSee('Xác thực bảo mật')->assertSee('Search Console')->assertDontSee('Chỉnh sửa Website');
    }

    public function test_connect_button_always_visible_and_explains_when_not_configured(): void
    {
        $s = $this->site();
        $this->actingAs($this->user('admin'));
        config(['gsc.oauth.client_id' => null, 'gsc.oauth.client_secret' => null]);
        Livewire::test(EditWebsite::class, ['record' => $s->getKey()])
            ->assertActionVisible('gscConnect')->callAction('gscConnect')->assertNotified('Chưa thể kết nối Google')->assertNoRedirect();
        config(['gsc.oauth.client_id' => 'cid', 'gsc.oauth.client_secret' => 'csecret']);
        Livewire::test(EditWebsite::class, ['record' => $s->getKey()])
            ->callAction('gscConnect')->assertRedirect(route('gsc.oauth.start', ['website' => $s->getKey()]));
    }

    public function test_check_action_reports_result_and_disconnect_revokes(): void
    {
        $s = $this->site();
        $this->actingAs($this->user('admin'));
        GscConnection::create(['google_email' => 'me@gmail.com', 'refresh_token' => 'rt']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]), 'www.googleapis.com/webmasters/*' => Http::response([], 403), 'oauth2.googleapis.com/revoke' => Http::response('')]);
        Livewire::test(EditWebsite::class, ['record' => $s->getKey()])
            ->assertSee('me@gmail.com')
            ->callAction('gscCheck')->assertNotified()
            ->callAction('gscDisconnect')->assertNotified();
        $this->assertNull(GscConnection::active());
    }

    public function test_oauth_returns_to_website_page_only_for_admin_and_only_known_site(): void
    {
        $s = $this->site();
        $this->actingAs($this->user('admin'));
        $this->get('/admin/search-console/oauth/start?website='.$s->getKey())->assertRedirectContains('accounts.google.com');
        $this->assertSame(WebsiteResource::getUrl('edit', ['record' => $s]).'?tab=website-search-console-tab', session('gsc_oauth_return'));
        $this->get('/admin/search-console/oauth/start?website=999999');
        $this->assertNull(session('gsc_oauth_return'));

        $this->actingAs($this->user('approver'));
        $this->get('/admin/search-console/oauth/start?website='.$s->getKey());
        $this->assertNull(session('gsc_oauth_return'), 'người duyệt không phải admin không được chuyển về trang Website');
    }

    public function test_callback_redirects_back_to_website_page(): void
    {
        $s = $this->site();
        $this->actingAs($this->user('admin'));
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]), 'openidconnect.googleapis.com/*' => Http::response(['email' => 'me@gmail.com'])]);
        $this->withSession(['gsc_oauth_state' => 'good', 'gsc_oauth_return' => WebsiteResource::getUrl('edit', ['record' => $s])])
            ->get('/admin/search-console/oauth/callback?state=good&code=c')
            ->assertRedirect(WebsiteResource::getUrl('edit', ['record' => $s]));
    }
}
