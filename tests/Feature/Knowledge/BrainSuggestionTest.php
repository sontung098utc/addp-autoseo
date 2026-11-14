<?php

namespace Tests\Feature\Knowledge;

use App\Filament\Pages\BrainBuildPage;
use App\Models\Brain;
use App\Models\KnowledgeGap;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainTemplates;
use App\Services\Knowledge\Suggest\KnowledgeSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Gợi ý "nên lập Trí tuệ cho lĩnh vực X" do điều phối ghi: hiện ở trang Xây kho và tạo được Trí tuệ bằng một nút. */
class BrainSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'Máy móc', 'cms_type' => 'wordpress', 'base_url' => 'https://m.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        KnowledgeGap::create(['website_id' => $this->site->id, 'query' => KnowledgeSuggestions::BRAIN_PREFIX.'Dinh dưỡng cho người tiểu đường', 'count' => 2, 'status' => 'open']);
    }

    private function admin(): User
    {
        return User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_goi_y_lap_tri_tue_hien_thanh_loai_brain_dung_dau(): void
    {
        $list = (new KnowledgeSuggestions)->forWebsite($this->site, 20);
        $this->assertSame('brain', $list[0]['type']);
        $this->assertSame('create_brain', $list[0]['action']);
        $this->assertSame('Dinh dưỡng cho người tiểu đường', $list[0]['query']);
        $this->assertStringContainsString('Nên lập Trí tuệ', $list[0]['title']);
    }

    public function test_nut_tao_tri_tue_tao_tu_mau_hop_nhat_gan_website_va_dong_goi_y(): void
    {
        $this->actingAs($this->admin());
        $key = (new KnowledgeSuggestions)->forWebsite($this->site, 20)[0]['key'];
        Livewire::test(BrainBuildPage::class)->set('websiteId', $this->site->id)->assertSee('Tạo Trí tuệ và gắn vào website')->call('createBrain', $key);

        $brain = Brain::where('name', 'Dinh dưỡng cho người tiểu đường')->first();
        $this->assertNotNull($brain);
        $this->assertSame('active', $brain->status);
        $this->assertTrue($brain->websites()->where('websites.id', $this->site->id)->exists());
        $this->assertSame('filled', KnowledgeGap::first()->status);
        $this->assertSame([], array_filter((new KnowledgeSuggestions)->forWebsite($this->site, 20), fn ($s) => $s['type'] === 'brain'), 'Gợi ý đã đóng.');
    }

    public function test_chon_mau_theo_tu_khoa_va_mac_dinh_la_mau_trong(): void
    {
        $this->assertSame('trong', BrainTemplates::suggestFor('Máy móc bào chế viên nén'));
        $this->assertNotSame('trong', BrainTemplates::suggestFor('Dinh dưỡng và chế độ ăn'));
    }

    public function test_nguoi_khong_co_quyen_khong_tao_duoc(): void
    {
        $this->actingAs(User::create(['name' => 'w', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));
        $this->get('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep')->assertForbidden();
        $this->assertSame(0, Brain::count());
    }

    public function test_tao_tri_tue_theo_dinh_huong_cua_bai_va_nap_san_nguon_tin_cay_tim_duoc(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'who.int/robots.txt' => \Illuminate\Support\Facades\Http::response('', 404),
            'who.int/gmp' => \Illuminate\Support\Facades\Http::response('<html><head><meta charset="utf-8"><title>GMP</title></head><body><main><h1>GMP</h1><p>Thiết bị dập viên phải được thẩm định lắp đặt vận hành và hiệu năng trước khi sản xuất.</p><p>'.str_repeat('Thông tin tham khảo cho ngành dược. ', 8).'</p></main></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $ai = new class implements \App\Contracts\StructuredAiInterface
        {
            public function generateStructured(string $system, string $user): array
            {
                return ['data' => ['items' => [['title' => 'Thẩm định thiết bị', 'body' => 'Thiết bị dập viên phải được thẩm định lắp đặt vận hành và hiệu năng trước khi sản xuất.', 'type' => 'fact', 'topic' => 'Chung', 'tags' => [], 'confidence' => 90,
                    'source_span' => 'Thiết bị dập viên phải được thẩm định lắp đặt vận hành và hiệu năng trước khi sản xuất']]], 'tokens_used' => 10, 'cost_vnd' => 5.0, 'model' => 'fake'];
            }
        };
        app()->instance(\App\Services\Knowledge\Discover\SourceDiscoveryService::class, new \App\Services\Knowledge\Discover\SourceDiscoveryService(new \App\Services\Knowledge\Build\SafePageFetcher(new \Tests\Feature\Knowledge\Build\FakeSafety, 0), null, null, null, $ai, null, 'AI giả'));

        $article = \App\Models\Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'máy dập viên một chày', 'workflow_status' => 'needs_review']);
        \App\Models\ArticleVersion::create(['article_id' => $article->id, 'version' => 1, 'title' => 'T', 'slug' => 't', 'html_content' => '<p>x</p>', 'outline' => [['level' => 'H2', 'heading' => 'Cơ chế nén — mô tả'], 'Chọn máy theo quy mô'], 'sources_cited' => [], 'status' => 'pending_review', 'seo_title' => 't', 'meta_description' => 'm', 'focus_keyword' => 'k']);
        $run = \App\Models\AgentRun::create(['website_id' => $this->site->id, 'article_id' => $article->id, 'job_type' => 'article_write', 'trigger' => 'manual', 'status' => 'ready_for_review', 'ai_mode' => 'real', 'started_at' => now()]);
        \App\Models\AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'research', 'role' => 'system', 'status' => 'ok', 'title' => 'Tìm tài liệu', 'detail' => 'd', 'data' => ['purpose' => 'references', 'references' => [
            ['url' => 'https://www.who.int/gmp', 'title' => 'GMP', 'domain' => 'who.int'], ['url' => 'https://hang-ban-may.vn/may-dap-vien', 'title' => 'Máy dập viên giá tốt', 'domain' => 'hang-ban-may.vn']]]]);
        \App\Models\AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 2, 'stage' => 'synthesize', 'role' => 'strategist', 'status' => 'ok', 'title' => 'Tổng hợp', 'detail' => 'd', 'data' => ['plan' => ['angle' => 'Chọn máy theo nhu cầu', 'questions' => ['Khác nhau thế nào?']]]]);
        \App\Models\AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 3, 'stage' => 'research', 'role' => 'system', 'status' => 'warn', 'title' => 'Đề xuất', 'detail' => 'd', 'data' => ['purpose' => 'kb_coordination', 'suggestion' => ['type' => 'new_brain', 'field' => 'Thiết bị sản xuất dược', 'covers' => ['Thiết bị sản xuất', 'GMP và thẩm định', 'Kiểm soát chất lượng']]]]);

        $admin = $this->admin();
        $r = (new \App\Services\Brain\BrainFromSuggestion)->create($this->site, 'Thiết bị sản xuất dược', $article, $admin);

        $brain = $r['brain'];
        $this->assertSame('Chọn máy theo nhu cầu', $brain->settings['build_direction']['angle']);
        $this->assertStringContainsString('máy dập viên một chày', (string) $brain->description);
        $this->assertSame(2 + 3, $r['topics'], '3 mảng của lĩnh vực rộng + 2 mục dàn ý của bài.');
        $this->assertSame(['Thiết bị sản xuất', 'GMP và thẩm định', 'Kiểm soát chất lượng'], \App\Models\KnowledgeTopic::where('brain_id', $brain->id)->whereNull('parent_id')->whereIn('name', ['Thiết bị sản xuất', 'GMP và thẩm định', 'Kiểm soát chất lượng'])->orderBy('id')->pluck('name')->all());
        $this->assertSame(['Thiết bị sản xuất', 'GMP và thẩm định', 'Kiểm soát chất lượng'], $brain->settings['build_direction']['covers']);
        $top = \App\Models\KnowledgeTopic::where('brain_id', $brain->id)->where('name', 'Từ bài «máy dập viên một chày»')->first();
        $this->assertNotNull($top);
        $this->assertSame(['Cơ chế nén', 'Chọn máy theo quy mô'], \App\Models\KnowledgeTopic::where('parent_id', $top->id)->orderBy('id')->pluck('name')->all(), 'Tiêu đề dàn ý (bỏ phần sau dấu —) thành chủ đề của kho.');
        $this->assertSame(1, $r['imported']);
        $this->assertSame(1, $r['items_auto'], 'Nguồn tin cậy có đoạn nguyên văn được tự nhận.');
        $this->assertSame('approved', \App\Models\KnowledgeItem::where('title', 'Thẩm định thiết bị')->first()->status);
        $this->assertSame($brain->id, \App\Models\KnowledgeItem::where('title', 'Thẩm định thiết bị')->first()->brain_id);
        $this->assertSame(1, $r['registered'], 'Nguồn thường được ghi vào Căn cứ chuẩn mực ở dạng chưa xác nhận.');
        $this->assertStringContainsString('Đã nạp 1 nguồn tin cậy', \App\Services\Brain\BrainFromSuggestion::summary($r));
    }
}
