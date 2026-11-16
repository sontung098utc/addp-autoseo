<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\WeeklySchedule;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Gộp "Lịch tuần" và "Lịch xuất bản" thành một trang hai tab ngang (Lịch tuần mặc định). */
class PublicationScheduleTabsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function pub(string $status = 'scheduled'): Publication
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw-gop', 'workflow_status' => 'scheduled']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);

        return Publication::create(['website_id' => $site->id, 'article_version_id' => $v->id, 'publication_key' => 'k-gop', 'scheduled_at_utc' => now()->addDay(), 'status' => $status]);
    }

    public function test_menu_nhom_lich_dang_chi_con_mot_muc(): void
    {
        $this->actingAs($this->user('approver'));
        $this->get('/admin');
        $labels = collect(Filament::getNavigation())
            ->filter(fn ($g) => $g->getLabel() === 'Lịch đăng')
            ->flatMap(fn ($g) => $g->getItems())
            ->map(fn ($i) => $i->getLabel())->values()->all();

        $this->assertSame(['Lịch xuất bản'], $labels);
    }

    public function test_hai_tab_dung_khoa_va_lich_tuan_la_mac_dinh(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get(PublicationResource::getUrl('index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-key="lich-tuan".*data-key="danh-sach"/s', $html);
        $this->assertStringContainsString("studioTabs('lich-tuan'", $html);
        $this->assertStringContainsString('Tuần này', $html);
    }

    public function test_url_cu_chuyen_ve_dung_tab(): void
    {
        $this->actingAs($this->user('approver'));
        $this->get('/admin/lich-tuan')->assertRedirect(PublicationResource::scheduleUrl());
        $this->assertStringEndsWith('/admin/publications#tab-lich-tuan', PublicationResource::scheduleUrl());
        $this->assertStringEndsWith('/admin/publications#tab-danh-sach', PublicationResource::listUrl());
        $this->assertFalse(WeeklySchedule::shouldRegisterNavigation());
    }

    public function test_url_cu_van_chan_nguoi_khong_co_quyen(): void
    {
        $this->actingAs($this->user('writer'));
        $this->get('/admin/lich-tuan')->assertForbidden();
    }

    public function test_canh_bao_dung_khan_cap_o_dau_trang_chung(): void
    {
        $this->actingAs($u = $this->user('approver'));
        app(EmergencyStopService::class)->stop($u, 'Sự cố kiểm thử');

        Livewire::test(ListPublications::class)
            ->assertSee('data-testid="stop-warning"', false)
            ->assertSee('DỪNG KHẨN CẤP')
            ->assertSee('Sự cố kiểm thử')
            ->assertActionVisible('resume');
    }

    public function test_nut_tam_dung_tung_luot_van_chay_trong_tab_danh_sach(): void
    {
        $this->actingAs($this->user('approver'));
        $p = $this->pub();

        Livewire::test(ListPublications::class)
            ->assertTableActionVisible('pausePublication', $p)
            ->callTableAction('pausePublication', $p);

        $this->assertSame('paused', $p->fresh()->status);
    }
}
