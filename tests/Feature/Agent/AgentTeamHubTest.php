<?php

namespace Tests\Feature\Agent;

use App\Filament\Pages\AiUsagePage;
use App\Models\AgentSetting;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Ai\GeminiAiProvider;
use App\Services\Ai\DemoScriptedAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S01d: một mục "Đội AI" gom các tab; kết nối AI lưu mã hóa, chỉ admin sửa; tổng quan dẫn người dùng đi đúng thứ tự. */
class AgentTeamHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('GOOGLE_AI_API_KEY');
        unset($_ENV['GOOGLE_AI_API_KEY'], $_SERVER['GOOGLE_AI_API_KEY']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    public function test_cac_tab_cung_mot_cum_va_biên_tap_vien_bi_chan(): void
    {
        $this->actingAs($this->user('approver'));
        foreach (['tong-quan', 'chien-dich', 'phien-chay', 'vai-tro'] as $tab) {
            $this->get("/admin/doi-ai/{$tab}")->assertOk()->assertSee('Tổng quan')->assertSee('Chiến dịch')->assertSee('Phiên chạy')->assertSee('Vai trò');
        }
    }

    public function test_bien_tap_vien_khong_vao_duoc_cum(): void
    {
        $this->actingAs($this->user('writer'));
        foreach (['tong-quan', 'chien-dich', 'phien-chay', 'vai-tro'] as $tab) {
            $this->get("/admin/doi-ai/{$tab}")->assertForbidden();
        }
    }

    public function test_menu_chi_con_mot_muc_doi_ai(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/>\s*Đội AI\s*</u', $html), 'chỉ một mục Đội AI trong menu');
        $this->assertStringNotContainsString('Bản đồ luồng đội AI', $html);
        $this->assertStringNotContainsString('Phiên chạy của đội AI', $html);
    }

    public function test_tong_quan_5_buoc_deu_chua_xong_khi_chua_co_gi(): void
    {
        $this->actingAs($this->user('approver'));

        // Chưa kết nối AI là việc CẦN làm: nổi bật ở khối "Cần bạn", không nhồi mọi thứ lên trang.
        $this->get('/admin/doi-ai/tong-quan')->assertOk()
            ->assertSee('Chưa kết nối AI, lượt tự động không chạy')->assertSee('Nhập khóa')
            ->assertSee('Chưa có website nào ở trạng thái hoạt động');
    }

    public function test_tong_quan_cap_nhat_buoc_khi_da_lam(): void
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        Campaign::create(['website_id' => $w->id, 'name' => 'CD', 'status' => 'active', 'daily_limit' => 5, 'keywords' => ['a', 'b', 'c']]);
        AgentSetting::create(['provider' => 'gemini', 'api_key' => 'AIza-test-1234', 'model' => 'gemini-1.5-flash']);
        $this->actingAs($this->user('admin'));

        $this->get('/admin/doi-ai/tong-quan')->assertOk()
            ->assertSee('1 website đang hoạt động')->assertDontSee('Chưa có khóa AI')->assertDontSee('Chưa kết nối AI nên');
    }

    private function steps(): array
    {
        $c = \Livewire\Livewire::test(\App\Filament\Clusters\AgentTeam\Pages\AgentOverview::class)->instance();
        $m = new \ReflectionMethod($c, 'getViewData');
        $m->setAccessible(true);

        return $m->invoke($c);
    }

    public function test_5_buoc_dem_dung_va_buoc_chua_co_du_lieu_luon_chua_lam(): void
    {
        $this->actingAs($this->user('admin'));
        $d = $this->steps();
        $this->assertSame([1, 2, 3, 4, 5], array_column($d['steps'], 'n'));
        $this->assertSame(['todo', 'todo', 'todo', 'default', 'todo'], array_column($d['steps'], 'state'));
        $this->assertSame(5, $d['stepsLeft']);
        $this->get('/admin/doi-ai/tong-quan')->assertOk()->assertSee('Hoàn tất cài đặt: còn 5 bước')
            ->assertSee('Hồ sơ doanh nghiệp')->assertSee('Nguồn AI được dùng')->assertSee('Cân bằng (mặc định)')->assertSee('Search Console chưa kết nối');

        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $c = Campaign::create(['website_id' => $w->id, 'name' => 'CD', 'status' => 'active', 'daily_limit' => 5, 'keywords' => ['a']]);
        $this->assertSame('done', $this->steps()['steps'][0]['state']);
        // Sau AG-S02 cột campaigns.mode có mặc định 'balanced': có chiến dịch nghĩa là đã có chế độ, bước 4 hoàn tất.
        $this->assertSame('done', $this->steps()['steps'][3]['state']);
        $this->assertSame(3, $this->steps()['stepsLeft']);

        $art = \App\Models\Article::create(['website_id' => $w->id, 'primary_keyword' => 'a']);
        \App\Models\AgentRun::create(['article_id' => $art->id, 'website_id' => $w->id, 'campaign_id' => $c->id, 'status' => 'held', 'ai_mode' => 'mock']);
        $d = $this->steps();
        $this->assertSame('done', $d['steps'][4]['state']);
        $this->assertSame(2, $d['stepsLeft']);
        $this->assertSame('todo', $d['steps'][1]['state']);
        $this->assertSame('todo', $d['steps'][2]['state']);
    }

    public function test_nhan_menu_bai_viet_gon_va_khong_con_hai_muc_chien_dich(): void
    {
        $this->actingAs($this->user('approver'));
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringNotContainsString('Chiến dịch &amp; Bài viết', $html);
        $this->assertSame(1, preg_match_all('/>\s*Bài viết\s*</u', $html));
        $this->assertSame(0, preg_match_all('/>\s*Duyệt bài\s*</u', $html));
        $this->get('/admin/articles')->assertOk()->assertDontSee('Chiến dịch &amp; Bài viết', false);
    }

    public function test_khoa_luu_ma_hoa_va_khong_bao_gio_hien_lai(): void
    {
        $this->actingAs($this->user('admin'));
        Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)
            ->callAction('addProvider', ['name' => 'Gemini chính', 'preset' => 'gemini', 'api_key' => 'AIzaSECRETKEY9999', 'suggest' => true])
            ->assertHasNoActionErrors();

        $raw = \DB::table('ai_providers')->value('api_key');
        $this->assertStringNotContainsString('SECRETKEY', (string) $raw, 'khóa phải được mã hóa trong CSDL');
        $this->assertSame('AIzaSECRETKEY9999', \App\Models\AiProvider::first()->api_key);
        $this->get('/admin/chi-phi-ai?tab=cau-hinh')->assertOk()->assertDontSee('SECRETKEY')->assertDontSee('AIzaSECRETKEY9999')->assertSee('•••• đã lưu')->assertSee('…9999');

        Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)->callAction('editLimits', ['run_budget_vnd' => 70000, 'max_repair_rounds' => 2])->assertHasNoActionErrors();
        $this->assertSame(70000.0, AgentAi::policy()->budgetVnd);
        $this->assertSame(2, AgentAi::policy()->maxRepairRounds);
    }

    public function test_cap_nhat_khoa_thay_khoa_cu(): void
    {
        $p = \App\Models\AiProvider::create(['name' => 'G', 'kind' => 'gemini', 'api_key' => 'AIza-OLD-1111']);
        $this->actingAs($this->user('admin'));

        Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)->callAction('updateKey', ['api_key' => 'AIza-NEW-2222'], ['id' => $p->id])->assertHasNoActionErrors();

        $this->assertSame('AIza-NEW-2222', $p->fresh()->api_key);
    }

    public function test_nguoi_duyet_xem_duoc_nhung_khong_sua_duoc(): void
    {
        $p = \App\Models\AiProvider::create(['name' => 'G', 'kind' => 'gemini', 'api_key' => 'AIza-OLD-1111']);
        $this->actingAs($this->user('approver'));

        $this->get('/admin/chi-phi-ai?tab=cau-hinh')->assertOk()->assertSee('Chỉ quản trị viên được thêm')->assertSee('Tác vụ nào chưa có khóa tài khoản sẽ không chạy')->assertDontSee('Sửa khóa');
        Livewire::withQueryParams(['tab' => 'cau-hinh'])->test(AiUsagePage::class)->assertActionHidden('addProvider')->assertActionHidden('updateKey', ['id' => $p->id])->assertActionHidden('deleteProvider', ['id' => $p->id]);
        $this->assertSame('AIza-OLD-1111', $p->fresh()->api_key);
    }

    public function test_ket_noi_khoa_trong_he_thong_uu_tien_hon_bien_moi_truong(): void
    {
        $this->assertNull(AgentAi::real());
        $this->assertFalse(AgentAi::status()['connected']);

        AgentSetting::create(['provider' => 'gemini', 'api_key' => 'AIza-DB-1234', 'model' => 'gemini-1.5-pro']);
        $st = AgentAi::status();

        $this->assertTrue($st['connected']);
        $this->assertSame('db', $st['source']);
        $this->assertSame('••••1234', $st['key_hint']);
        $this->assertInstanceOf(GeminiAiProvider::class, AgentAi::real());
    }

    public function test_thieu_khoa_ngoai_local_khong_co_ai_gia_lap_im_lang(): void
    {
        $this->assertNull(AgentAi::forInteractiveUse());

        $this->app->detectEnvironment(fn () => 'local');
        $this->assertInstanceOf(DemoScriptedAiProvider::class, AgentAi::forInteractiveUse());
    }

    public function test_ping_khong_lo_khoa_trong_thong_bao_loi(): void
    {
        $p = new class('AIza-LEAK-0000', 'm') extends GeminiAiProvider {
            protected function callGeminiApi(string $systemInstruction, string $userContent): array
            {
                throw new \RuntimeException('Client error: `POST https://x/models/m:generateContent?key=AIza-LEAK-0000` resulted in 403');
            }
        };
        $r = $p->ping();

        $this->assertFalse($r['ok']);
        $this->assertStringNotContainsString('LEAK', $r['message']);
        $this->assertTrue((new class('k', 'm') extends GeminiAiProvider {
            protected function callGeminiApi(string $systemInstruction, string $userContent): array
            {
                return ['text' => '{"ok":true}'];
            }
        })->ping()['ok']);
        $this->assertFalse((new GeminiAiProvider('', 'm'))->ping()['ok']);
    }

    // ---- AG-S08: tổng quan gọn, chỉ điều cần chú ý ----

    public function test_khong_co_viec_thi_chi_noi_binh_thuong_va_khong_nhoi_so_lieu(): void
    {
        AgentSetting::create(['provider' => 'gemini', 'api_key' => 'AIza-test-1234', 'model' => 'm']);
        $this->actingAs($this->user('approver'));

        $html = $this->get('/admin/doi-ai/tong-quan')->assertOk()->assertSee('Không có việc nào cần bạn lúc này')->assertSee('Đang nghỉ')->getContent();
        $this->assertStringNotContainsString('data-live-run', $html);
        $this->assertStringNotContainsString('Đường đi của một bài', $html, 'đường đi của bài chỉ ở cửa sổ phụ');
        $this->assertStringNotContainsString('data-stage=', $html, 'không còn lưới chặng gộp mọi phiên');
    }

    public function test_khoi_can_ban_chi_hien_viec_that_va_xep_theo_muc_nghiem_trong(): void
    {
        AgentSetting::create(['provider' => 'gemini', 'api_key' => 'AIza-test-1234', 'model' => 'm']);
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $art = \App\Models\Article::create(['website_id' => $w->id, 'primary_keyword' => 'a']);
        \App\Models\AgentRun::create(['article_id' => $art->id, 'website_id' => $w->id, 'status' => 'held', 'ai_mode' => 'mock']);
        \App\Models\AgentRun::create(['article_id' => $art->id, 'website_id' => $w->id, 'status' => 'failed', 'ai_mode' => 'mock']);
        $this->actingAs($this->user('approver'));

        $html = $this->get('/admin/doi-ai/tong-quan')->assertOk()->assertDontSee('Không có việc nào cần bạn')->getContent();
        $this->assertLessThan(strpos($html, '1 phiên giữ nháp'), strpos($html, '1 phiên bị lỗi'), 'lỗi nổi lên trước giữ nháp');
        $this->assertStringNotContainsString('chờ bạn duyệt', $html, 'không có bản nháp thì không liệt kê');
    }

    public function test_so_lieu_hom_nay_va_cua_so_luong_co_du_lieu(): void
    {
        AgentSetting::create(['provider' => 'gemini', 'api_key' => 'AIza-test-1234', 'model' => 'm']);
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $art = \App\Models\Article::create(['website_id' => $w->id, 'primary_keyword' => 'a']);
        \App\Models\AgentRun::create(['article_id' => $art->id, 'website_id' => $w->id, 'status' => 'ready_for_review', 'ai_mode' => 'mock', 'started_at' => now(), 'cost_vnd' => 1234]);
        $this->actingAs($this->user('approver'));

        $this->get('/admin/doi-ai/tong-quan')->assertOk()->assertSee('bài đã giao hôm nay')->assertSee('1.234 ₫');
    }

    public function test_bang_phien_chay_va_chien_dich_cot_noi_dung_rong_cot_phu_gon(): void
    {
        $this->actingAs($this->user('approver'));
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $art = \App\Models\Article::create(['website_id' => $w->id, 'primary_keyword' => 'a']);
        \App\Models\AgentRun::create(['article_id' => $art->id, 'website_id' => $w->id, 'status' => 'held', 'ai_mode' => 'mock', 'started_at' => now(), 'outcome_reason' => 'Dừng vì điểm không tăng']);
        Campaign::create(['website_id' => $w->id, 'name' => 'CD', 'status' => 'active', 'daily_limit' => 5, 'keywords' => ['a']]);

        $runs = $this->get('/admin/doi-ai/phien-chay')->assertOk()->getContent();
        $camp = $this->get('/admin/doi-ai/chien-dich')->assertOk()->getContent();
        $this->assertStringContainsString('ss-col-main', $runs);
        $this->assertStringContainsString('width:1%;white-space:nowrap', $runs);
        $this->assertStringContainsString('ss-col-main', $camp);
        $this->assertStringContainsString('width:1%;white-space:nowrap', $camp);
    }
}
