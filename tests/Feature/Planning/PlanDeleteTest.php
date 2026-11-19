<?php

namespace Tests\Feature\Planning;

use App\Filament\Pages\ContentPlanPage;
use App\Filament\Support\Audit\AuditRecorder;
use App\Filament\Support\Audit\AuditSink;
use App\Filament\Support\ContentPlan\PlanDeleter;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class PlanDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private object $sink;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'ADDP', 'cms_type' => 'wordpress', 'base_url' => 'https://addp.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->sink = new class implements AuditSink
        {
            public array $entries = [];

            public function write(array $entry): void
            {
                $this->entries[] = $entry;
            }
        };
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    private function deleter(): PlanDeleter
    {
        return new PlanDeleter(new AuditRecorder($this->sink));
    }

    private function article(string $kw, ?Campaign $c = null, string $status = 'planned'): Article
    {
        return Article::create(['website_id' => $this->site->id, 'campaign_id' => $c?->id, 'primary_keyword' => $kw, 'workflow_status' => $status]);
    }

    private function campaign(string $name = 'Kế hoạch A', string $status = 'paused'): Campaign
    {
        return Campaign::create(['website_id' => $this->site->id, 'name' => $name, 'status' => $status, 'source' => 'plan']);
    }

    private function withVersion(Article $a): Article
    {
        ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>', 'seo_title' => 'T', 'meta_description' => 'm', 'focus_keyword' => 'k', 'review_status' => 'needs_review']);

        return $a;
    }

    public function test_xoa_bai_cho_viet_ghi_nhat_ky_va_giu_bai_da_viet(): void
    {
        $a = $this->article('sữa hạt a');
        $b = $this->article('sữa hạt b');
        $written = $this->withVersion($this->article('đã viết rồi'));
        $drafting = $this->article('đang viết', null, 'drafting');

        $r = $this->deleter()->deletePlanned($this->user('approver'), $this->site, [$a->id, $b->id, $written->id, $drafting->id]);

        $this->assertSame(2, $r['deleted']);
        $this->assertCount(2, $r['skipped']);
        $this->assertNull(Article::find($a->id));
        $this->assertNotNull(Article::find($written->id), 'bài đã có bản viết không bao giờ bị xóa');
        $this->assertNotNull(Article::find($drafting->id));
        $this->assertCount(1, $this->sink->entries);
        $this->assertSame('delete_plan', $this->sink->entries[0]['action']);
        $this->assertSame(2, $this->sink->entries[0]['extra']['deleted']);
    }

    public function test_xoa_bai_tra_tu_khoa_ve_chua_dung_khi_khong_con_bai_nao_dung(): void
    {
        $approver = $this->user('approver');
        $k1 = Keyword::create(['website_id' => $this->site->id, 'keyword' => 'sữa hạt', 'keyword_key' => 'sữa hạt', 'status' => 'planned']);
        $k2 = Keyword::create(['website_id' => $this->site->id, 'keyword' => 'sữa óc chó', 'keyword_key' => 'sữa óc chó', 'status' => 'planned']);
        $a = $this->article('sữa hạt');
        $a->forceFill(['keyword_id' => $k1->id])->save();
        $b = $this->article('sữa óc chó');
        $b->forceFill(['keyword_id' => $k2->id])->save();
        $other = $this->withVersion($this->article('sữa óc chó 2', null, 'needs_review'));
        $other->forceFill(['keyword_id' => $k2->id])->save();

        $this->deleter()->deletePlanned($approver, $this->site, [$a->id, $b->id]);

        $this->assertNotNull(Keyword::find($k1->id), 'từ khóa vẫn còn trong kho');
        $this->assertSame('new', $k1->fresh()->status);
        $this->assertSame('has_article', $k2->fresh()->status, 'còn bài khác dùng thì giữ nguyên');
    }

    public function test_bai_co_phien_doi_ai_khong_xoa_duoc_du_con_o_trang_thai_ke_hoach(): void
    {
        $a = $this->article('đã giao đội AI');
        AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'status' => 'failed', 'ai_mode' => 'mock', 'trust_tier' => 1]);

        $r = $this->deleter()->deletePlanned($this->user('approver'), $this->site, [$a->id]);

        $this->assertSame(0, $r['deleted']);
        $this->assertNotNull(Article::find($a->id));
        $this->assertSame([], $this->sink->entries, 'không xóa gì thì không ghi nhật ký xóa');
    }

    public function test_bai_cua_website_khac_khong_bi_xoa(): void
    {
        $other = Website::create(['name' => 'Khác', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $other->id, 'primary_keyword' => 'bài site khác', 'workflow_status' => 'planned']);

        $r = $this->deleter()->deletePlanned($this->user('approver'), $this->site, [$a->id]);

        $this->assertSame(0, $r['deleted']);
        $this->assertNotNull(Article::find($a->id));
    }

    public function test_xoa_ca_nhom_bai_le_khong_dung_bai_trong_chien_dich(): void
    {
        $c = $this->campaign();
        $inCampaign = $this->article('trong chiến dịch', $c);
        $this->article('lẻ 1');
        $this->article('lẻ 2');

        $r = $this->deleter()->deleteGroupPlanned($this->user('approver'), $this->site, 'solo');

        $this->assertSame(2, $r['deleted']);
        $this->assertNotNull(Article::find($inCampaign->id));
        $this->assertSame(1, Article::count());
        $this->expectException(InvalidArgumentException::class);
        $this->deleter()->deleteGroupPlanned($this->user('approver'), $this->site, 'solo');   // hết bài để xóa: báo rõ
    }

    public function test_xoa_chien_dich_va_bai_cho_viet_cua_no(): void
    {
        $c = $this->campaign('Gửi sữa hạt');
        $this->article('x1', $c);
        $this->article('x2', $c);
        $keep = $this->article('lẻ giữ nguyên');

        $r = $this->deleter()->deleteCampaign($this->user('approver'), $this->site, $c, true);

        $this->assertSame(['name' => 'Gửi sữa hạt', 'deleted' => 2, 'kept' => 0], $r);
        $this->assertNull(Campaign::find($c->id));
        $this->assertSame([$keep->id], Article::pluck('id')->all());
        $this->assertSame('delete_campaign', $this->sink->entries[0]['action']);
    }

    public function test_xoa_chien_dich_giu_bai_thanh_bai_le(): void
    {
        $c = $this->campaign();
        $a = $this->article('x1', $c);
        $b = $this->article('x2', $c);

        $r = $this->deleter()->deleteCampaign($this->user('approver'), $this->site, $c, false);

        $this->assertSame(0, $r['deleted']);
        $this->assertSame(2, $r['kept']);
        $this->assertNull(Campaign::find($c->id));
        $this->assertNull($a->fresh()->campaign_id);
        $this->assertNull($b->fresh()->campaign_id);
    }

    public function test_chien_dich_dang_chay_da_viet_hoac_da_co_phien_khong_xoa_duoc(): void
    {
        $approver = $this->user('approver');

        $active = $this->campaign('Đang chạy', 'active');
        $this->article('x', $active);
        try {
            $this->deleter()->deleteCampaign($approver, $this->site, $active, true);
            $this->fail('chiến dịch đang chạy phải bị chặn');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Tạm dừng', $e->getMessage());
        }

        $mixed = $this->campaign('Có bài đã viết');
        $this->article('chờ', $mixed);
        $this->withVersion($this->article('đã viết', $mixed, 'needs_review'));
        try {
            $this->deleter()->deleteCampaign($approver, $this->site, $mixed, true);
            $this->fail('chiến dịch có bài đã viết phải bị chặn');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('1 bài đã viết', $e->getMessage());
        }
        $this->assertSame(2, Article::where('campaign_id', $mixed->id)->count(), 'bị chặn thì không mất bài nào (giao dịch hoàn tác)');

        $ran = $this->campaign('Đã chạy');
        $a = $this->article('x', $ran);
        AgentRun::create(['website_id' => $this->site->id, 'campaign_id' => $ran->id, 'article_id' => null, 'status' => 'done', 'ai_mode' => 'mock', 'trust_tier' => 1]);
        try {
            $this->deleter()->deleteCampaign($approver, $this->site, $ran, true);
            $this->fail('chiến dịch đã có phiên chạy phải bị chặn');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('phiên chạy', $e->getMessage());
        }
        $this->assertNotNull(Campaign::find($ran->id));
        $this->assertNotNull(Article::find($a->id));
        $this->assertSame([], $this->sink->entries);
    }

    public function test_chi_truong_nhom_tro_len_moi_xoa_duoc(): void
    {
        $a = $this->article('x');
        foreach (['writer'] as $role) {
            try {
                $this->deleter()->deletePlanned($this->user($role), $this->site, [$a->id]);
                $this->fail('vai trò thấp phải bị chặn');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('trưởng nhóm', $e->getMessage());
            }
        }
        try {
            $this->deleter()->deletePlanned($this->user('approver', false), $this->site, [$a->id]);
            $this->fail('tài khoản bị khóa phải bị chặn');
        } catch (InvalidArgumentException $e) {
            $this->assertNotNull(Article::find($a->id));
        }
    }

    public function test_hop_thoai_tren_trang_xoa_bai_chien_dich_va_bao_dung_ket_qua(): void
    {
        $approver = $this->user('approver');
        $c = $this->campaign('Chiến dịch thử');
        $a = $this->article('bài một', $c);
        $b = $this->article('bài hai', $c);
        $loose = $this->article('bài lẻ');

        $page = Livewire::actingAs($approver)->test(ContentPlanPage::class);
        $page->callAction('deletePlanned', [], ['article' => $a->id])->assertNotified('Đã xóa 1 bài khỏi kế hoạch');
        $this->assertNull(Article::find($a->id));

        $page->callAction('deleteGroup', [], ['group' => 'solo'])->assertNotified('Đã xóa 1 bài khỏi kế hoạch');
        $this->assertNull(Article::find($loose->id));
        $this->assertNotNull(Article::find($b->id));

        $page->callAction('deleteCampaign', ['mode' => 'keep'], ['campaign' => $c->id])->assertNotified('Đã xóa chiến dịch «Chiến dịch thử», giữ 1 bài thành bài lẻ');
        $this->assertNull(Campaign::find($c->id));
        $this->assertNull($b->fresh()->campaign_id);
    }

    public function test_trang_hien_nut_xoa_cho_truong_nhom_va_an_voi_writer(): void
    {
        $c = $this->campaign('Chiến dịch thử');
        $this->article('bài một', $c);
        $this->article('bài lẻ');

        Livewire::actingAs($this->user('approver'))->test(ContentPlanPage::class)
            ->assertSee('Xóa 1 bài chờ viết')->assertSee('Xóa chiến dịch này')->assertSee('Xóa bài này khỏi kế hoạch', false);
        Livewire::actingAs($this->user('writer'))->test(ContentPlanPage::class)
            ->assertDontSee('Xóa chiến dịch này')->assertDontSee('bài chờ viết</button>', false);
    }

    public function test_writer_khong_goi_duoc_hop_thoai_xoa(): void
    {
        $a = $this->article('x');
        $writer = $this->user('writer');
        try {
            Livewire::actingAs($writer)->test(ContentPlanPage::class)->callAction('deletePlanned', [], ['article' => $a->id]);
        } catch (\Throwable) {
            // bị chặn bằng 403 hoặc không hiện hành động: miễn là bài còn nguyên
        }
        $this->assertNotNull(Article::find($a->id));
    }
}
