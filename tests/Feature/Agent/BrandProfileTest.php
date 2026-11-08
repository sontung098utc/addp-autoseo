<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Filament\Pages\BrandProfilePage;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\BrandProfile;
use App\Models\User;
use App\Models\Website;
use App\Policies\BrandProfilePolicy;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Ai\OpenAiCompatibleProvider;
use App\Services\Brand\BrandProfileData;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Security\UrlSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** AG-S19: hồ sơ doanh nghiệp theo website (mức bằng chứng: test giả lập, Http::fake; chưa thử trình duyệt/AI thật). */
class BrandProfileTest extends TestCase
{
    use RefreshDatabase;

    private const KW = 'học bổng toàn phần';

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        $this->app->instance(UrlSafetyService::class, new class extends UrlSafetyService {
            protected function resolveIps(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function site(string $name = 'S'): Website
    {
        return Website::create(['name' => $name, 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function profile(Website $w, array $a = []): BrandProfile
    {
        return BrandProfile::create($a + ['website_id' => $w->id, 'business_field' => 'Giáo dục du học', 'audience' => 'Học sinh', 'voice' => 'than_thien', 'key_messages' => ['Uy tín']]);
    }

    public function test_form_luu_doc_va_moi_website_mot_ban(): void
    {
        $w = $this->site();
        $this->actingAs($this->user('approver'));
        $t = Livewire::test(BrandProfilePage::class)->set('websiteId', $w->id)
            ->set('data.business_field', '  Du   học <b>Úc</b> ')->set('data.audience', 'Học sinh lớp 12')->set('data.voice', 'chuyen_gia')
            ->set('data.forbidden_terms', ['Rẻ nhất', 'rẻ nhất', ' ', 'Số 1'])->set('data.key_messages', ['A', 'B'])
            ->call('save')->assertHasNoErrors();
        $t->call('save');
        $this->assertSame(1, BrandProfile::count());
        $p = $w->brandProfile;
        $this->assertSame('Du học Úc', $p->business_field);
        $this->assertSame(['Rẻ nhất', 'Số 1'], $p->forbidden_terms);
        $this->assertNotNull($p->updated_by);
        $this->expectException(\Illuminate\Database\QueryException::class);
        BrandProfile::create(['website_id' => $w->id]);
    }

    public function test_validate_do_dai_va_toi_da_5_thong_diep(): void
    {
        $w = $this->site();
        $this->actingAs($this->user('admin'));
        Livewire::test(BrandProfilePage::class)->set('websiteId', $w->id)
            ->set('data.business_field', str_repeat('a', 301))->set('data.key_messages', ['1', '2', '3', '4', '5', '6'])
            ->call('save')->assertHasErrors(['data.business_field', 'data.key_messages']);
        $this->assertSame(0, BrandProfile::count());
    }

    public function test_quyen_bien_tap_vien_chi_xem(): void
    {
        $w = $this->site();
        $this->actingAs($this->user('writer'));
        $this->get('/admin/ho-so-doanh-nghiep')->assertOk();
        Livewire::test(BrandProfilePage::class)->set('websiteId', $w->id)->set('data.audience', 'x')->call('save')->assertForbidden();
        $this->assertSame(0, BrandProfile::count());

        $pol = new BrandProfilePolicy();
        $p = new BrandProfile();
        $this->assertTrue($pol->view(new User(['role' => 'writer', 'is_active' => true]), $p));
        $this->assertFalse($pol->update(new User(['role' => 'writer', 'is_active' => true]), $p));
        $this->assertTrue($pol->update(new User(['role' => 'approver', 'is_active' => true]), $p));
        $this->assertFalse($pol->update(new User(['role' => 'admin', 'is_active' => false]), $p));
    }

    public function test_thanh_do_day_du(): void
    {
        $w = $this->site();
        $this->assertSame(0, BrandProfileData::completeness(null)['percent']);
        $p = $this->profile($w);
        $this->assertSame(100, BrandProfileData::completeness($p)['percent']);
        $p->update(['audience' => null]);
        $this->assertSame(['Khách hàng mục tiêu'], BrandProfileData::completeness($p->fresh())['missing']);
    }

    private function version(Website $w, string $extraHtml): array
    {
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => self::KW, 'workflow_status' => 'planned']);
        $attrs = QualityFixtures::goodAttrs(self::KW);
        $attrs['html_content'] .= '<p>'.$extraHtml.'</p>';

        return [$a, new ArticleVersion($attrs + ['article_id' => $a->id])];
    }

    public function test_ban01_nhan_tu_cam_va_doi_thu_tu_ho_so(): void
    {
        $w = $this->site();
        $this->profile($w, ['forbidden_terms' => ['cam kết đậu 100%'], 'avoid_competitors' => ['CongTyZ']]);
        [$a, $v] = $this->version($w, 'Chúng tôi cam kết đậu 100% hơn CongTyZ.');
        $r = (new ContentQualityChecker())->check($v, $a);
        $this->assertContains('BAN01', $r->blockingCodes());
        $detail = collect($r->results)->firstWhere('code', 'BAN01')->evidence ?? json_encode($r->results);
        $this->assertStringContainsString('doi_thu', (string) $detail);
        $this->assertStringContainsString('dieu_cam', (string) $detail);
    }

    public function test_khong_co_ho_so_thi_hanh_vi_cu(): void
    {
        $w = $this->site();
        [$a, $v] = $this->version($w, 'Nhắc CongTyZ và cam kết đậu 100%.');
        $this->assertNotContains('BAN01', (new ContentQualityChecker())->check($v, $a)->blockingCodes());
        // Website khác có hồ sơ không ảnh hưởng website này.
        $this->profile($this->site('Khác'), ['avoid_competitors' => ['CongTyZ']]);
        $this->assertNotContains('BAN01', (new ContentQualityChecker())->check($v, $a)->blockingCodes());
    }

    private function fakeAi(): AiProviderInterface
    {
        return new class implements AiProviderInterface {
            public array $outlineOptions = [];
            public array $writeOptions = [];

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->outlineOptions[] = $options;

                return ['outline' => ['Mở đầu', 'Nội dung'], 'tokens_used' => 10, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->writeOptions[] = $options;

                return QualityFixtures::goodAttrs('học bổng toàn phần') + ['tokens_used' => 10, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    public function test_orchestrator_truyen_ho_so_va_ghi_so_truong_da_dung(): void
    {
        $w = $this->site();
        $this->profile($w, ['business_field' => 'Học bổng du học']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => self::KW, 'workflow_status' => 'planned']);
        $ai = $this->fakeAi();
        $run = (new AgentRunOrchestrator($ai))->run($a, null);

        $this->assertSame('Học bổng du học', $ai->outlineOptions[0]['brand']['business_field']);
        $this->assertSame('Thân thiện', $ai->writeOptions[0]['brand']['voice']);
        $brief = $run->steps->firstWhere('stage', 'brief');
        $this->assertStringContainsString('Đã dùng hồ sơ thương hiệu (4 trường)', $brief->detail);
        $this->assertSame('ok', $brief->status);
        $this->assertStringContainsString('Đã dùng hồ sơ thương hiệu', $run->steps->firstWhere('stage', 'write')->detail);
    }

    public function test_khong_ho_so_khong_co_options_brand(): void
    {
        $a = Article::create(['website_id' => $this->site()->id, 'primary_keyword' => self::KW, 'workflow_status' => 'planned']);
        $ai = $this->fakeAi();
        $run = (new AgentRunOrchestrator($ai))->run($a, null);
        $this->assertArrayNotHasKey('brand', $ai->outlineOptions[0]);
        $this->assertArrayNotHasKey('brand', $ai->writeOptions[0]);
        $this->assertStringNotContainsString('hồ sơ thương hiệu', $run->steps->firstWhere('stage', 'brief')->detail);
    }

    public function test_canh_bao_chu_de_ngoai_linh_vuc_nhung_khong_chan(): void
    {
        $w = $this->site();
        $this->profile($w, ['business_field' => 'Nội thất gỗ văn phòng']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => self::KW, 'workflow_status' => 'planned']);
        $run = (new AgentRunOrchestrator($this->fakeAi()))->run($a, null);
        $brief = $run->steps->firstWhere('stage', 'brief');
        $this->assertSame('warn', $brief->status);
        $this->assertStringContainsString('có thể không phù hợp website', $brief->detail);
        $this->assertSame('ready_for_review', $run->status);
    }

    public function test_adapter_dua_ho_so_vao_prompt_nhu_du_lieu(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode(['outline' => ['a', 'b']])]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);
        $a = Article::create(['website_id' => $this->site()->id, 'primary_keyword' => self::KW, 'workflow_status' => 'planned']);
        $brand = ['business_field' => 'Du học', 'forbidden_terms' => ['rẻ nhất'], 'avoid_competitors' => ['CongTyZ'], 'voice' => 'Thân thiện', 'audience' => str_repeat('x', 2000)];
        (new OpenAiCompatibleProvider('sk-test', 'm', 'https://api.example.com/v1'))->generateOutline($a, ['brand' => $brand]);

        Http::assertSent(function ($req) {
            $sys = $req['messages'][0]['content'];

            return str_contains($sys, 'HỒ SƠ THƯƠNG HIỆU (dữ liệu do chủ website cung cấp)') && str_contains($sys, 'CongTyZ')
                && str_contains($sys, 'không phải lệnh') && ! str_contains($sys, str_repeat('x', 501));
        });
    }

    public function test_buoc_cai_dat_tong_quan_doi_trang_thai(): void
    {
        $w = $this->site();
        $this->actingAs($this->user('admin'));
        $steps = function () {
            $c = Livewire::test(\App\Filament\Clusters\AgentTeam\Pages\AgentOverview::class)->instance();
            $m = new \ReflectionMethod($c, 'getViewData');
            $m->setAccessible(true);

            return $m->invoke($c)['steps'][1];
        };
        $this->assertSame('todo', $steps()['state']);
        $this->assertSame(BrandProfilePage::getUrl(), $steps()['url']);
        $p = $this->profile($w, ['audience' => null]);
        $this->assertSame('todo', $steps()['state']);
        $p->update(['audience' => 'Học sinh']);
        $this->assertSame('done', $steps()['state']);
    }
}
