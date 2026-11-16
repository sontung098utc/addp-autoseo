<?php

namespace Tests\Feature\Knowledge;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\Brain;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Readiness\BrainAutoAttach;
use App\Services\Brain\BrainFromSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Lập kho từ gợi ý không được nhân bản kho đã có: dùng lại kho cùng lĩnh vực, chọn mẫu theo tên lĩnh vực, nạp nguồn về đúng Trí tuệ, gắn Trí tuệ có sẵn không bị trần 3. */
class BrainRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private Brain $dd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->dd = Brain::create(['name' => 'Đái tháo đường (tiểu đường)', 'slug' => 'dd', 'domain_key' => 'dai_thao_duong', 'status' => 'active']);
    }

    private function admin(): User
    {
        return User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_dung_lai_kho_khi_linh_vuc_da_co_nhung_van_lap_duoc_kho_hep_hon(): void
    {
        $this->assertSame($this->dd->id, BrainFromSuggestion::overlapping('Đái tháo đường')?->id, 'lĩnh vực trùng kho có sẵn (kể cả chưa gắn website)');
        $this->assertNull(BrainFromSuggestion::overlapping('Nội tiết và chuyển hóa'), 'lĩnh vực rộng khác tên: không coi là trùng');
        Brain::create(['name' => 'Dinh dưỡng và chế độ ăn', 'slug' => 'dn', 'domain_key' => 'dinh_duong', 'status' => 'active']);
        $this->assertNull(BrainFromSuggestion::overlapping('Dinh dưỡng cho người tiểu đường'), 'kho HẸP hơn kho có sẵn thì vẫn được lập riêng');
        Brain::create(['name' => 'Trí tuệ của Website X', 'slug' => 'x', 'domain_key' => 'thuong_hieu', 'status' => 'active']);
        $this->assertNull(BrainFromSuggestion::overlapping('Trí tuệ của Website X'), 'Trí tuệ thương hiệu không bị dùng lại');
    }

    public function test_tao_kho_trung_linh_vuc_thi_dung_lai_va_gan_website_khong_lap_moi(): void
    {
        $before = Brain::count();
        $r = (new BrainFromSuggestion)->create($this->site, 'Đái tháo đường', null, $this->admin());

        $this->assertTrue($r['reused']);
        $this->assertSame($this->dd->id, $r['brain']->id);
        $this->assertSame($before, Brain::count(), 'không lập kho trùng');
        $this->assertTrue($this->dd->websites()->where('websites.id', $this->site->id)->exists(), 'kho có sẵn được gắn vào website');
        $this->assertStringContainsString('dùng lại trí tuệ có sẵn', mb_strtolower(BrainFromSuggestion::summary($r)));
    }

    public function test_mau_chon_theo_ten_linh_vuc_khong_keo_nham_mau_cua_benh_da_co_kho(): void
    {
        $article = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'tiền tiểu đường phải làm sao', 'workflow_status' => 'planned']);
        $run = AgentRun::create(['article_id' => $article->id, 'website_id' => $this->site->id, 'status' => 'ready_for_review', 'trigger' => 'manual', 'started_at' => now(), 'ai_mode' => 'real']);
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'research', 'role' => 'system', 'status' => 'warn', 'title' => 'Đề xuất', 'detail' => 'd',
            'data' => ['purpose' => 'kb_coordination', 'suggestion' => ['type' => 'new_brain', 'field' => 'Nội tiết và chuyển hóa', 'covers' => ['Đái tháo đường và biến chứng', 'Bệnh lý tuyến giáp', 'Rối loạn chuyển hóa lipid'], 'sensitive' => true]]]);

        $r = (new BrainFromSuggestion)->create($this->site, 'Nội tiết và chuyển hóa', $article, $this->admin());

        $this->assertFalse($r['reused']);
        $this->assertNotSame('dai_thao_duong', $r['template'], 'các mảng nhắc tới đái tháo đường không được kéo mẫu của bệnh đã có kho riêng');
        $this->assertNotSame('dai_thao_duong', $r['brain']->domain_key);
        $this->assertSame(2, Brain::count(), 'lập một kho mới cho lĩnh vực rộng, kho Đái tháo đường giữ nguyên');
    }

    public function test_nguon_nap_ve_dung_tri_tue_theo_linh_vuc(): void
    {
        $target = Brain::create(['name' => 'Nội tiết và chuyển hóa', 'slug' => 'nt', 'domain_key' => 'noi_tiet', 'status' => 'active']);
        Brain::create(['name' => 'Đái tháo đường (thử quy trình)', 'slug' => 'dd-thu', 'domain_key' => 'dai_thao_duong', 'status' => 'active']); // bản thử cùng lĩnh vực: bỏ qua
        $refs = [
            ['url' => 'https://www.cdc.gov/diabetes/signs-symptoms/index.html', 'title' => 'Diabetes Symptoms'],
            ['url' => 'https://medlineplus.gov/diabetes.html', 'title' => 'Diabetes'],
            ['url' => 'https://www.thyroid.org/hypothyroidism', 'title' => 'Tổng quan về nội tiết'],
        ];
        $groups = (new BrainFromSuggestion)->routeSources($refs, $target, 'Nội tiết và chuyển hóa');

        $by = collect($groups)->keyBy(fn ($g) => $g['brain']->id);
        $this->assertCount(2, $groups);
        $this->assertSame([$refs[0]['url'], $refs[1]['url']], array_column($by[$this->dd->id]['refs'], 'url'), 'nguồn về bệnh đã có kho riêng vào kho bệnh đó (bản gốc, không phải bản thử)');
        $this->assertSame([$refs[2]['url']], array_column($by[$target->id]['refs'], 'url'));
    }

    public function test_gan_tri_tue_co_san_khong_bi_tran_ba_nhung_goi_y_tu_dong_van_bi(): void
    {
        foreach (['A', 'B', 'C'] as $i => $n) {
            $b = Brain::create(['name' => 'Kho '.$n, 'slug' => 'k'.$i, 'domain_key' => 'k'.$i, 'status' => 'active']);
            $b->websites()->attach($this->site->id, ['priority' => ($i + 1) * 10]);
        }
        $article = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'tiểu đường tuýp 2', 'workflow_status' => 'planned']);
        $this->assertSame([], BrainAutoAttach::candidates($article, $this->site->brains()->pluck('brains.id')->all()), 'trần 3 vẫn chặn việc tự gợi ý theo từ khóa');

        BrainAutoAttach::attach($this->site, $this->dd, 'Điều phối xác định bài thuộc lĩnh vực này', 100, $article);
        $this->assertSame(4, $this->site->brains()->count());
        $this->assertSame('Điều phối xác định bài thuộc lĩnh vực này', Brain::find($this->dd->id)->settings['auto_attached'][(string) $this->site->id]['reason']);
        BrainAutoAttach::detach($this->site, Brain::find($this->dd->id), null);
        $this->assertSame(3, $this->site->brains()->count(), 'hoàn tác được');
    }
}
