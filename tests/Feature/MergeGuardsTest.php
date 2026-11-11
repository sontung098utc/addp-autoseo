<?php

namespace Tests\Feature;

use App\Contracts\PublisherInterface;
use App\Jobs\PublishArticleJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Ai\AbstractLlmProvider;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Publishing\PublisherManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** Rào chắn khi gộp với lô A/PR-D của Dev1: chiến dịch mặc định tạm dừng, lượt đăng đang giữ không bao giờ gửi, cột chuỗi bị cắt đúng độ dài. */
class MergeGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://s.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    public function test_chien_dich_tao_ra_khong_dat_status_la_tam_dung_va_nhip_khong_chay(): void
    {
        $c = Campaign::create(['website_id' => $this->site()->id, 'name' => 'X', 'keywords' => ['abc def']]);

        $this->assertSame('paused', $c->fresh()->status);
        $r = (new CampaignRunner(fn () => new DemoScriptedAiProvider))->tick($c->fresh());
        $this->assertSame('paused', $r['result']);
    }

    public function test_chien_dich_chi_co_target_keywords_van_duoc_doc_tu_khoa(): void
    {
        if (! Schema::hasColumn('campaigns', 'target_keywords')) {
            $this->markTestSkipped('Cột target_keywords thuộc lô migration A của Dev1 (có sau khi gộp develop).');
        }
        $c = Campaign::create(['website_id' => $this->site()->id, 'name' => 'X', 'status' => 'active', 'target_keywords' => ['đường huyết cao']]);

        $this->assertSame('đường huyết cao', (new CampaignRunner(fn () => new DemoScriptedAiProvider))->nextKeyword($c));
    }

    private function publication(string $status): Publication
    {
        $w = $this->site();
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'k', 'workflow_status' => 'approved']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved', 'tokens_used' => 1, 'cost_vnd' => 1]);

        return Publication::create(['website_id' => $w->id, 'article_version_id' => $v->id, 'publication_key' => 'k-'.$status, 'scheduled_at_utc' => now()->subHour(), 'timezone' => 'Asia/Ho_Chi_Minh', 'status' => $status]);
    }

    private function run_job(Publication $p): void
    {
        $publisher = Mockery::mock(PublisherInterface::class);
        $publisher->shouldNotReceive('publish');
        $publisher->shouldNotReceive('createDraft');
        $manager = new PublisherManager;
        $manager->registerPublisher('wordpress', $publisher);

        (new PublishArticleJob($p))->handle($manager);
    }

    /** @dataProvider holding */
    public function test_luot_dang_dang_giu_khong_bao_gio_duoc_gui(string $status): void
    {
        $p = $this->publication($status);
        $this->run_job($p);

        $this->assertSame($status, $p->fresh()->status);
    }

    /** @dataProvider holding */
    public function test_dung_khan_cap_khong_xoa_diem_cho_nguoi(string $status): void
    {
        $admin = User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $p = $this->publication($status);
        app(EmergencyStopService::class)->stop($admin, 'thử', $p->website_id);

        $this->run_job($p);

        $this->assertSame($status, $p->fresh()->status, 'dừng khẩn cấp không được đặt lại lượt đang giữ thành scheduled');
    }

    public static function holding(): array
    {
        return ['held' => ['held'], 'paused' => ['paused'], 'awaiting_confirm' => ['awaiting_confirm']];
    }

    public function test_seo_title_va_meta_bi_cat_dung_do_dai_cot(): void
    {
        $fit = new \ReflectionMethod(AbstractLlmProvider::class, 'fit');
        $fit->setAccessible(true);

        $this->assertSame(70, mb_strlen($fit->invoke(null, str_repeat('Ư', 200), 70)));
        $this->assertSame(160, mb_strlen($fit->invoke(null, str_repeat('ê', 500), 160)));
        $this->assertNull($fit->invoke(null, null, 70));
    }
}
