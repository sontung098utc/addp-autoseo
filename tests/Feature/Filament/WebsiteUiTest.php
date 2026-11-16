<?php

namespace Tests\Feature\Filament;

use App\Contracts\PublisherInterface;
use App\Filament\Resources\WebsiteResource\Pages\EditWebsite;
use App\Filament\Resources\WebsiteResource\Pages\ListWebsites;
use App\Filament\Support\Audit\AuditSink;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\PublisherManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteUiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'S3CRET-TEST-VALUE';

    private const URL = 'https://8.8.8.8';

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => $role.'@test.local', 'password' => 'test-password-123', 'role' => $role, 'is_active' => true]);
    }

    private function site(array $o = []): Website
    {
        return Website::create($o + [
            'name' => 'Site thử', 'cms_type' => 'wordpress', 'base_url' => self::URL,
            'store_scope' => 'default', 'status' => 'active',
            'encrypted_credentials' => ['username' => 'u', 'application_password' => self::SECRET],
        ]);
    }

    private function as(string $role): void
    {
        $this->actingAs($this->user($role));
    }

    public function test_credential_khong_xuat_hien_trong_html(): void
    {
        $this->as('admin');
        $w = $this->site();
        Livewire::test(ListWebsites::class)->assertSuccessful()->assertDontSee(self::SECRET)
            ->mountTableAction('view', $w)->assertDontSee(self::SECRET);
        Livewire::test(EditWebsite::class, ['record' => $w->id])->assertSuccessful()->assertDontSee(self::SECRET);
    }

    public function test_slide_over_hien_chua_co_du_lieu_nhat_ky(): void
    {
        $this->as('admin');
        $w = $this->site();
        Livewire::test(ListWebsites::class)->mountTableAction('view', $w)
            ->assertSee('Nhật ký kết nối 90 ngày')->assertSee('Chưa có dữ liệu');
    }

    public function test_doi_credential_ghi_duoc_va_khong_lo_html(): void
    {
        $this->as('admin');
        $w = $this->site();
        Livewire::test(ListWebsites::class)
            ->callTableAction('rotateCredentials', $w, ['username' => 'new', 'application_password' => 'NEW-SECRET-9'])
            ->assertHasNoTableActionErrors()->assertDontSee('NEW-SECRET-9');
        $this->assertSame('NEW-SECRET-9', $w->fresh()->encrypted_credentials['application_password']);
    }

    public function test_thu_hoi_thieu_ly_do_bi_chan(): void
    {
        $this->as('admin');
        $w = $this->site();
        Livewire::test(ListWebsites::class)->callTableAction('revoke', $w, ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertSame('active', $w->fresh()->status);
    }

    public function test_thu_hoi_thanh_cong_giu_bai_va_ghi_audit(): void
    {
        $sink = new class implements AuditSink
        {
            public array $entries = [];

            public function write(array $entry): void
            {
                $this->entries[] = $entry;
            }
        };
        $this->app->instance(AuditSink::class, $sink);
        $this->as('admin');
        $w = $this->site();
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'k', 'search_intent' => 'informational']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>']);
        Publication::create(['website_id' => $w->id, 'article_version_id' => $v->id, 'publication_key' => 'k1', 'scheduled_at_utc' => now(), 'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'scheduled']);

        Livewire::test(ListWebsites::class)->callTableAction('revoke', $w, ['reason' => 'Hết hợp đồng'])->assertHasNoTableActionErrors();

        $w->refresh();
        $this->assertSame('revoked', $w->status);
        $this->assertEmpty($w->encrypted_credentials);
        $this->assertCount(1, $sink->entries);
        $this->assertSame('revoke_website', $sink->entries[0]['action']);
        $this->assertSame($w->id, $sink->entries[0]['extra']['website_id']);
        $this->assertSame(1, Article::count());
        $this->assertSame(1, ArticleVersion::count());
        $this->assertSame(1, Publication::count());
    }

    public function test_khoi_phuc_sau_thu_hoi_ve_pending(): void
    {
        $this->as('admin');
        $w = $this->site(['status' => 'revoked', 'encrypted_credentials' => []]);
        Livewire::test(ListWebsites::class)
            ->assertTableActionHidden('revoke', $w)->assertTableActionHidden('testConnection', $w)
            ->callTableAction('rotateCredentials', $w, ['username' => 'u', 'application_password' => 'x-new']);
        $this->assertSame('pending', $w->fresh()->status);
    }

    public function test_non_admin_bi_tu_choi(): void
    {
        foreach (['writer', 'approver'] as $role) {
            $this->as($role);
            Livewire::test(ListWebsites::class)->assertForbidden();
        }
    }

    public function test_url_http_va_ip_noi_bo_bi_chan(): void
    {
        $this->as('admin');
        $w = $this->site();
        foreach (['http://8.8.4.4', 'https://127.0.0.1', 'https://10.0.0.1', 'https://169.254.169.254'] as $url) {
            Livewire::test(EditWebsite::class, ['record' => $w->id])
                ->fillForm(['base_url' => $url])->call('save')->assertHasFormErrors(['base_url']);
        }
    }

    public function test_trung_base_url_store_scope_bi_chan_nhung_sua_chinh_no_thi_khong(): void
    {
        $this->as('admin');
        $a = $this->site();
        $b = $this->site(['base_url' => 'https://8.8.4.4']);
        Livewire::test(EditWebsite::class, ['record' => $b->id])
            ->fillForm(['base_url' => self::URL])->call('save')->assertHasFormErrors(['base_url']);
        Livewire::test(EditWebsite::class, ['record' => $a->id])
            ->fillForm(['name' => 'Đổi tên'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Đổi tên', $a->fresh()->name);
    }

    public function test_kiem_tra_ket_noi_khong_ho_tro_khong_bao_thanh_cong(): void
    {
        $this->as('admin');
        $w = $this->site();
        $this->app->instance(PublisherManager::class, new class extends PublisherManager
        {
            public function getPublisher(Website $website): PublisherInterface
            {
                throw new \InvalidArgumentException('Nền tảng CMS không được hỗ trợ (giả lập: cột cms_type là enum nên không lưu được giá trị lạ).');
            }
        });
        Livewire::test(ListWebsites::class)->callTableAction('testConnection', $w)
            ->assertNotified('Chưa hỗ trợ kiểm tra kết nối cho CMS này');
        $this->assertNull($w->fresh()->last_synced_at);
    }

    public function test_cot_phu_an_o_man_hep_va_thao_tac_gom_vao_mot_menu(): void
    {
        $this->as('admin');
        $this->site();
        $c = Livewire::test(ListWebsites::class);
        $table = $c->instance()->getTable();
        foreach (['cms_type', 'last_synced_at'] as $name) {
            $col = $table->getColumn($name);
            $prop = (new \ReflectionClass($col))->getProperty('visibleFrom');
            $prop->setAccessible(true);
            $this->assertNotNull($prop->getValue($col), "cột $name phải ẩn ở màn hẹp");
        }
        $this->assertCount(1, $table->getActions(), 'mọi thao tác nằm trong một menu ba chấm');
    }
}
