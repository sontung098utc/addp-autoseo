<?php

namespace Tests\Feature\Brain;

use App\Filament\Clusters\KhoTriTue;
use App\Filament\Pages\BrainLibraryPage;
use App\Filament\Pages\BrainPage;
use App\Filament\Pages\KnowledgePage;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\CampaignResource;
use App\Models\Article;
use App\Models\Brain;
use App\Models\BrainProfile;
use App\Models\Campaign;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainContext;
use App\Services\Brain\BrainService;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-B1: giao diện Kho trí tuệ (thư viện, trang một Trí tuệ, quyền, chiến dịch chọn Trí tuệ). Mức: Livewire test giả lập; chưa xem bằng trình duyệt. */
class BrainUiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function site(string $name = 'S'): Website
    {
        return Website::create(['name' => $name, 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function brain(string $name = 'Y khoa', string $tpl = 'y_khoa_duoc', array $sites = []): Brain
    {
        return (new BrainService)->createFromTemplate($name, $tpl, $sites, $this->user('admin'));
    }

    private function item(Brain $b, array $a = []): KnowledgeItem
    {
        $a += ['brain_id' => $b->id, 'type' => 'fact', 'title' => 'Mục '.uniqid(), 'body' => 'Nội dung mục kiến thức đủ dài.', 'status' => 'approved', 'version' => 1, 'created_by' => 'rule'];
        $a['content_hash'] = KnowledgeDedupe::hash($a['title'], $a['body']);

        return KnowledgeItem::create($a);
    }

    public function test_url_cu_chuyen_huong_va_menu_la_kho_tri_tue(): void
    {
        $this->actingAs($this->user('admin'));
        $this->get('/admin/kho-kien-thuc')->assertRedirect('/admin/kho-tri-tue/tri-tue');
        $this->get('/admin/kho-tri-tue')->assertRedirect('/admin/kho-tri-tue/tri-tue'); // Z2: cụm Kho trí tuệ, tab đầu là Trí tuệ
        $this->get('/admin/kho-tri-tue/tri-tue')->assertOk()->assertSee('Kho trí tuệ');
        $this->assertFalse(KnowledgePage::shouldRegisterNavigation());
        $this->assertTrue(BrainLibraryPage::shouldRegisterNavigation());
        $this->assertSame('Khám phá', KhoTriTue::getNavigationGroup()); // nhóm menu nay nằm ở cụm
    }

    public function test_thu_vien_hien_so_dem_that(): void
    {
        $w = $this->site('Trang A');
        $b = $this->brain('Y khoa', 'y_khoa_duoc', [$w->id]);
        $this->item($b, ['status' => 'approved']);
        $this->item($b, ['status' => 'proposed']);
        $this->item($b, ['status' => 'proposed']);
        $this->item($b, ['status' => 'approved', 'valid_until' => now()->subDays(3)->toDateString()]);
        $this->item($b, ['entity' => 'E', 'field' => 'giá', 'value' => '1', 'status' => 'approved']);
        $this->item($b, ['entity' => 'E', 'field' => 'giá', 'value' => '2', 'status' => 'proposed']);
        KnowledgeSource::create(['brain_id' => $b->id, 'title' => 'N1', 'raw_text' => 'x']);
        KnowledgeGap::create(['brain_id' => $b->id, 'website_id' => $w->id, 'query' => 'q', 'status' => 'open']);
        $other = $this->brain('Giáo dục', 'giao_duc');
        $this->item($other);

        $this->actingAs($this->user('approver'));
        $t = Livewire::test(BrainLibraryPage::class)->assertOk();
        $c = $t->viewData('cards')->keyBy(fn ($x) => $x['brain']->id);
        $this->assertSame(2, $c->count());
        $this->assertSame(1, $c[$b->id]['sources']);
        $this->assertSame(6, $c[$b->id]['items']);
        $this->assertSame(3, $c[$b->id]['proposed']);
        $this->assertSame(1, $c[$b->id]['conflicts']);
        $this->assertSame(1, $c[$b->id]['expired']);
        $this->assertSame(1, $c[$b->id]['gaps']);
        $this->assertSame(['Trang A'], $c[$b->id]['sites']);
        $this->assertSame(1, $c[$other->id]['items']);
        $t->assertSee('Y khoa')->assertSee('Bắt buộc nguồn')->assertSee('3 chờ duyệt')->assertSee('1 mâu thuẫn')->assertSee('Trang A')->assertSee('Tạo trí tuệ');
    }

    public function test_tao_tri_tue_tu_modal_va_quyen(): void
    {
        $w = $this->site();
        $this->actingAs($this->user('admin'));
        Livewire::test(BrainLibraryPage::class)->call('openCreate')->assertSet('showCreate', true)->call('pickKind', 'domain')->assertSee('Tên')
            ->set('create.template', 'giao_duc')->set('create.name', 'Giáo dục du học')->set('create.websites', [(string) $w->id])
            ->call('createBrain')->assertHasNoErrors();
        $b = Brain::where('name', 'Giáo dục du học')->firstOrFail();
        $this->assertSame('giao_duc', $b->template_key);
        $this->assertSame([$w->id], $b->websites()->pluck('websites.id')->all());

        Livewire::test(BrainLibraryPage::class)->call('openCreate')->call('pickKind', 'domain')->set('create.name', '  ')->call('createBrain');
        $this->assertSame(1, Brain::count(), 'tên trống không tạo');

        $this->actingAs($this->user('writer'));
        Livewire::test(BrainLibraryPage::class)->assertOk()->assertDontSee('Tạo trí tuệ')->call('openCreate')->assertForbidden();
        Livewire::test(BrainLibraryPage::class)->call('createBrain')->assertForbidden();
        $this->assertSame(1, Brain::count());
    }

    public function test_trang_mot_tri_tue_luu_ho_so_va_loc_theo_brain(): void
    {
        $b = $this->brain('Y khoa');
        $o = $this->brain('Khác', 'trong');
        $this->item($b, ['title' => 'Của Y khoa']);
        $this->item($o, ['title' => 'Của Khác']);
        $this->actingAs($this->user('approver'));

        $t = Livewire::test(BrainPage::class, ['brain' => $b->id])->assertOk()
            ->assertSee('Hồ sơ lĩnh vực')->assertSee('Cài đặt')->assertSee('Giọng và văn phong')->assertSee('Của Y khoa')->assertDontSee('Của Khác');
        $t->set('profileData.voice.tone', 'Điềm tĩnh')->set('profileData.rubric_notes', 'Ghi chú rubric')
            ->set('profileData.rules', "Quy tắc một\nQuy tắc hai")->call('saveProfile')->assertHasNoErrors();
        $p = $b->fresh()->profile;
        $this->assertSame('Điềm tĩnh', $p->voice['tone']);
        $this->assertSame(['Quy tắc một', 'Quy tắc hai'], $p->reasoning['rules']);
        $this->assertSame('Ghi chú rubric', $p->rubric_notes);
        $this->assertNotEmpty($p->structure['article_types'], 'khung mẫu được giữ khi lưu');
        $this->assertSame(1, BrainProfile::where('brain_id', $b->id)->count());
    }

    public function test_tai_lieu_va_chu_de_them_o_trang_tri_tue_gan_brain_id(): void
    {
        $b = $this->brain('Y khoa');
        $this->actingAs($this->user('admin'));
        Livewire::test(BrainPage::class, ['brain' => $b->id])
            ->set('paste.title', 'Tài liệu')->set('paste.text', "Tiêu đề một\n\nNội dung đoạn một đủ dài để tách.")->call('pasteDoc')
            ->set('new.title', 'Mục tay')->set('new.body', 'Nội dung mục tay đủ dài.')->call('addItem')
            ->set('topicName', 'Chủ đề riêng')->call('addTopic');
        $this->assertSame($b->id, KnowledgeSource::where('title', 'Tài liệu')->value('brain_id'));
        $this->assertSame($b->id, KnowledgeItem::where('title', 'Mục tay')->value('brain_id'));
        $this->assertGreaterThan(0, KnowledgeItem::where('source_id', KnowledgeSource::where('title', 'Tài liệu')->value('id'))->where('brain_id', $b->id)->count());
        $this->assertSame($b->id, KnowledgeTopic::where('name', 'Chủ đề riêng')->value('brain_id'));
    }

    public function test_hien_thi_noi_dung_ho_so_duoc_escape(): void
    {
        $b = $this->brain('Thử', 'trong');
        $b->profile->update(['rubric_notes' => '<script>alert(9)</script>Ghi chú', 'voice' => ['tone' => '<img src=x onerror=alert(1)>', 'do' => [], 'dont' => []]]);
        $b->update(['name' => 'Tên <b>đậm</b>']);
        $this->actingAs($this->user('admin'));
        $html = Livewire::test(BrainPage::class, ['brain' => $b->id])->html();
        $this->assertStringNotContainsString('<script>alert(9)', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('Tên <b>đậm</b>', $html);
        $lib = Livewire::test(BrainLibraryPage::class)->html();
        $this->assertStringNotContainsString('Tên <b>đậm</b>', $lib);
    }

    public function test_bien_tap_vien_chi_xem_khong_sua(): void
    {
        $b = $this->brain('Y khoa');
        $this->item($b, ['title' => 'Đã duyệt công khai']);
        $this->item($b, ['title' => 'Nháp bí mật', 'status' => 'proposed']);
        $this->actingAs($this->user('writer'));
        $t = Livewire::test(BrainPage::class, ['brain' => $b->id])->assertOk()->assertSee('Chỉ xem')->assertSee('Đã duyệt công khai')->assertDontSee('Nháp bí mật')->assertDontSee('Lưu Hồ sơ lĩnh vực');
        $t->call('saveProfile')->assertForbidden();
        Livewire::test(BrainPage::class, ['brain' => $b->id])->call('saveSettings')->assertForbidden();
        Livewire::test(BrainPage::class, ['brain' => $b->id])->call('toggleArchive')->assertForbidden();
        $this->assertSame('active', $b->fresh()->status);
    }

    public function test_cai_dat_luu_mức_website_va_luu_tru(): void
    {
        $w1 = $this->site('A');
        $w2 = $this->site('B');
        $b = $this->brain('Y khoa', 'y_khoa_duoc', [$w1->id]);
        $this->actingAs($this->user('admin'));
        $t = Livewire::test(BrainPage::class, ['brain' => $b->id])
            ->set('settings.ai_reliance', 'free')->set('settings.risk_class', 'medium')
            ->set("siteLinks.{$w1->id}.on", false)->set("siteLinks.{$w2->id}.on", true)->set("siteLinks.{$w2->id}.priority", 5)
            ->call('saveSettings');
        $b = $b->fresh();
        $this->assertSame('free', $b->reliance());
        $this->assertSame('medium', $b->riskClass());
        $this->assertSame([$w2->id => 5], $b->websites()->get()->mapWithKeys(fn ($w) => [$w->id => (int) $w->pivot->priority])->all());
        $t->call('toggleArchive');
        $this->assertSame('archived', $b->fresh()->status);
        $this->assertSame(0, Livewire::test(BrainLibraryPage::class)->viewData('cards')->count());
        $this->assertSame(1, Livewire::test(BrainLibraryPage::class)->set('showArchived', true)->viewData('cards')->count());
        $t->call('toggleArchive');
        $this->assertSame('active', $b->fresh()->status);
    }

    public function test_chien_dich_chon_tri_tue_va_thu_tu_uu_tien(): void
    {
        $w = $this->site('A');
        $b1 = $this->brain('Một', 'trong', [$w->id]);
        $b2 = $this->brain('Hai', 'thuong_hieu', [$w->id]);
        $b3 = $this->brain('Ba', 'trong');
        $this->actingAs($this->user('approver'));
        Livewire::test(CampaignResource\Pages\CreateCampaign::class)
            ->fillForm(['name' => 'CD', 'website_id' => $w->id, 'keywords' => ['kw'], 'daily_limit' => 1, 'status' => 'paused', 'mode' => 'balanced', 'source' => 'keywords', 'daily_cost_cap_vnd' => 1000, 'daily_draft_cap' => 1, 'brain_ids' => [$b3->id, $b1->id]])
            ->call('create')->assertHasNoErrors();
        $c = Campaign::firstOrFail();
        $this->assertEquals([$b3->id, $b1->id], $c->brain_ids);

        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'kw', 'workflow_status' => 'planned']);
        $this->assertSame([$b3->id, $b1->id], BrainContext::forArticle($a, $c->id)->ids(), 'theo lựa chọn của chiến dịch, đúng thứ tự');
        $this->assertSame([$b1->id, $b2->id], BrainContext::forArticle($a, null)->ids(), 'không chọn: theo website, theo ưu tiên rồi id');
        $c->update(['brain_ids' => null]);
        $this->assertSame([$b1->id, $b2->id], BrainContext::forArticle($a, $c->id)->ids());
        $b1->update(['status' => 'archived']);
        $this->assertSame([$b2->id], BrainContext::forArticle($a, null)->ids(), 'Trí tuệ lưu trữ không được dùng');
    }

    public function test_bai_viet_hien_tri_tue_dang_dung(): void
    {
        $w = $this->site('A');
        $this->brain('Trí tuệ Alpha', 'trong', [$w->id]);
        Article::create(['website_id' => $w->id, 'primary_keyword' => 'từ khóa x', 'workflow_status' => 'planned']);
        $this->actingAs($this->user('admin'));
        Livewire::test(ListArticles::class)->assertOk()->assertSee('Trí tuệ Alpha');
    }
}
