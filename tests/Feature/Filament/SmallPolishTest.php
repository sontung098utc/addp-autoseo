<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\WeeklySchedule;
use App\Filament\Resources\WebsiteResource\Pages as WebsitePages;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SmallPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_tao_website_chi_luu_credential_dung_loai_cms(): void
    {
        $this->actingAs($this->user('admin'));

        // Gửi cả ô WordPress lẫn Magento (như khi người dùng đổi CMS giữa chừng): chỉ ô của CMS đang chọn được lưu.
        Livewire::test(WebsitePages\CreateWebsite::class)
            ->fillForm([
                'name' => 'M', 'cms_type' => 'magento2', 'base_url' => 'https://8.8.4.4', 'store_scope' => 'default',
                'credentials_input' => [
                    'username' => 'wp-user', 'application_password' => 'WP-SECRET-XYZ',
                    'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'access_token' => 'at', 'access_token_secret' => 'ats',
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $creds = Website::where('name', 'M')->firstOrFail()->encrypted_credentials;
        $this->assertSame(['consumer_key', 'consumer_secret', 'access_token', 'access_token_secret'], array_keys($creds));
        $this->assertArrayNotHasKey('application_password', $creds);
        $this->assertArrayNotHasKey('username', $creds);
    }

    public function test_form_tao_website_an_o_credential_cho_den_khi_chon_cms(): void
    {
        $this->actingAs($this->user('admin'));

        Livewire::test(WebsitePages\CreateWebsite::class)
            ->assertSee('Chọn hệ thống CMS ở trên')
            ->assertDontSee('Consumer Key')
            ->assertDontSee('Application Password')
            ->fillForm(['cms_type' => 'wordpress'])
            ->assertSee('Application Password (WordPress)')
            ->assertDontSee('Consumer Key');
    }

    public function test_tha_vao_ngay_da_qua_van_nhan_va_bao_ly_do(): void
    {
        $now = CarbonImmutable::parse('2026-11-11 03:00:00', 'UTC'); // thứ Tư 10:00 giờ VN
        CarbonImmutable::setTestNow($now);
        \Illuminate\Support\Carbon::setTestNow($now);

        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw', 'workflow_status' => 'scheduled']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        $p = Publication::create(['website_id' => $site->id, 'article_version_id' => $v->id, 'publication_key' => 'k-1', 'scheduled_at_utc' => CarbonImmutable::parse('2026-11-12 02:30:00', 'UTC'), 'status' => 'scheduled']);

        $this->actingAs($this->user('approver'));

        $html = Livewire::test(WeeklySchedule::class)->html();
        // Ngày đã qua (thứ Hai 09/11) phải có bộ xử lý thả để thông báo lý do thay vì im lặng.
        $this->assertMatchesRegularExpression('/data-day="2026-11-09"[^>]*x-on:drop\.prevent/s', $html);

        Livewire::test(WeeklySchedule::class)
            ->call('moveToDay', $p->id, '2026-11-09')
            ->assertNotified('Không đổi được lịch');
        $this->assertSame('2026-11-12 02:30:00', $p->fresh()->getRawOriginal('scheduled_at_utc'));
    }

    public function test_tao_bai_ghi_nguoi_tao_va_tu_duyet_phai_co_ghi_chu(): void
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $approver = $this->user('approver');
        $this->actingAs($approver);

        \Livewire\Livewire::test(\App\Filament\Resources\ArticleResource\Pages\CreateArticle::class)
            ->fillForm(['website_id' => $site->id, 'primary_keyword' => 'kw tự tạo', 'search_intent' => 'informational'])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::where('primary_keyword', 'kw tự tạo')->firstOrFail();
        $this->assertSame($approver->id, $article->created_by);
        $this->assertSame('planned', $article->workflow_status);

        // Người tạo tự duyệt bài của mình phải bị buộc ghi chú (cờ self_approved bật nhờ created_by).
        $v = ArticleVersion::create(['article_id' => $article->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'needs_review']);
        $article->update(['workflow_status' => 'needs_review']);
        $this->expectException(\InvalidArgumentException::class);
        (new \App\Filament\Support\Audit\AuditRecorder(new class implements \App\Filament\Support\Audit\AuditSink {
            public function write(array $entry): void {}
        }))->record(\App\Filament\Support\Audit\AuditAction::Approve, $approver, $article, $v, null);
    }
}
