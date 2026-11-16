<?php

namespace Tests\Feature\Agent\Refresh;

use App\Contracts\AiProviderInterface;
use App\Contracts\PartialRefreshInterface;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use App\Services\Agent\Refresh\AgentRunDispatcher;
use App\Services\Agent\Refresh\ArticleRefreshRunner;
use App\Services\Agent\Refresh\HtmlSections;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Ai\MockAiProvider;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** G5: làm mới bài sửa một phần. AI giả đếm lời gọi để kiểm LUỒNG và CỔNG, không kiểm chất lượng AI thật. */
class ArticleRefreshRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const KW = 'học bổng toàn phần';

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
    }

    private function article(string $kind = 'refresh', array $versionAttrs = [], bool $withVersion = true): Article
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => self::KW, 'workflow_status' => 'approved', 'kind' => $kind]);
        if ($withVersion) {
            QualityFixtures::version($a, 1, 'approved', $versionAttrs);
        }

        return $a;
    }

    /** @param array{meta?:array,sections?:\Closure} $o */
    private function fake(array $o = []): object
    {
        return new class($o) implements AiProviderInterface, PartialRefreshInterface
        {
            public int $meta = 0;

            public int $sections = 0;

            public int $articles = 0;

            public int $outlines = 0;

            public array $sent = [];

            public function __construct(private array $o) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->outlines++;

                return ['outline' => ['a'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->articles++;

                return QualityFixtures::goodAttrs($article->primary_keyword) + ['tokens_used' => 10, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function regenerateMeta(Article $article, ArticleVersion $current, array $issues, array $options = []): array
            {
                $this->meta++;
                $m = $this->o['meta'] ?? ['seo_title' => 'Học bổng toàn phần: điều kiện và hồ sơ', 'meta_description' => str_pad('Hướng dẫn học bổng toàn phần cho sinh viên: điều kiện xét tuyển, hồ sơ cần chuẩn bị và thời gian đăng ký chi tiết', 130, '.')];

                return $m + ['tokens_used' => 100, 'cost_vnd' => 5.0, 'model' => 'fake-meta', 'prompt_version' => 't'];
            }

            public function repairSections(Article $article, ArticleVersion $current, array $sections, array $feedback, array $options = []): array
            {
                $this->sections++;
                $out = [];
                foreach ($sections as $s) {
                    $this->sent[] = $s['index'];
                    $out[$s['index']] = '<h2>'.$s['heading'].'</h2><p>Mục đã sửa, ảnh <img src="/m/a.jpg" alt="ảnh minh họa"></p>';
                }

                return ['sections' => $out, 'tokens_used' => 200, 'cost_vnd' => 7.0, 'model' => 'fake-sec', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    private function runIt(Article $a, object $ai, ?RunPolicy $p = null): AgentRun
    {
        return (new ArticleRefreshRunner($ai, policy: $p ?? new RunPolicy))->run($a);
    }

    private function longTitle(): array
    {
        // 65 ký tự: vượt ngưỡng META01 (60) nhưng vừa cột seo_title (70) nên chạy được cả trên MariaDB strict.
        return ['seo_title' => mb_substr(str_repeat('Học bổng toàn phần rất dài ', 4), 0, 65)];
    }

    public function test_chi_loi_meta_chi_goi_regenerate_meta_va_giu_nguyen_than_bai(): void
    {
        $a = $this->article('refresh', $this->longTitle());
        $old = $a->versions()->first();
        $ai = $this->fake();
        $run = $this->runIt($a, $ai);

        $this->assertSame('ready_for_review', $run->status, (string) $run->outcome_reason);
        $this->assertSame('article_refresh', $run->job_type);
        $this->assertSame([1, 0, 0, 0], [$ai->meta, $ai->sections, $ai->articles, $ai->outlines]);
        $new = $run->finalVersion;
        $this->assertSame(2, $new->version);
        $this->assertSame($old->html_content, $new->html_content);
        $this->assertNotSame($old->seo_title, $new->seo_title);
        $this->assertSame($old->sources_cited, $new->sources_cited);
        $this->assertSame('needs_review', $new->review_status);
        $this->assertSame('needs_review', $a->refresh()->workflow_status);
        $this->assertSame(['guard', 'grade_old', 'scope', 'meta', 'grade', 'handoff'], $run->steps->pluck('stage')->all());
        $this->assertSame('meta', $run->steps[2]->data['scope']);
        $this->assertSame(100, $new->tokens_used);
        $this->assertSame(5.0, (float) $new->cost_vnd);
    }

    public function test_loi_mot_muc_chi_gui_muc_do_va_cac_muc_khac_giu_nguyen_byte(): void
    {
        $html = '<p>Bài giới thiệu '.self::KW.' cho sinh viên.</p><h2>Một</h2><p>'.QualityFixtures::words(300, 1).'</p><h2>Hai '.self::KW.'</h2><p>'.QualityFixtures::words(300, 2).' <img src="/m/a.jpg"></p><h2>Ba</h2><p>'.QualityFixtures::words(300, 3).'</p><h2>Bốn</h2><p>'.QualityFixtures::words(300, 4).'</p>';
        $a = $this->article('refresh', ['html_content' => $html]);
        $ai = $this->fake();
        $run = $this->runIt($a, $ai);

        $this->assertSame(1, $ai->sections);
        $this->assertSame([1], $ai->sent, 'Chỉ mục có ảnh thiếu alt được gửi');
        $this->assertSame(0, $ai->articles + $ai->outlines + $ai->meta);
        $old = HtmlSections::split($html)['sections'];
        $new = HtmlSections::split($run->finalVersion->html_content);
        foreach ([0, 2, 3] as $i) {
            $this->assertSame($old[$i]['html'], $new['sections'][$i]['html']);
        }
        $this->assertStringContainsString('Mục đã sửa', $new['sections'][1]['html']);
        $sec = $run->steps->firstWhere('stage', 'section');
        $this->assertSame([1, 4], [$sec->data['sections_sent'], $sec->data['sections_total']]);
    }

    public function test_loi_nang_roi_ve_orchestrator_va_gan_nhan_article_refresh(): void
    {
        $a = $this->article('refresh', ['html_content' => '<h2>Ngắn</h2><p>Quá ngắn.</p>']);
        $ai = $this->fake();
        $run = $this->runIt($a, $ai);

        $this->assertSame('article_refresh', $run->job_type);
        $this->assertSame(1, $ai->articles);
        $this->assertSame(0, $ai->meta + $ai->sections);
        $this->assertContains('write', $run->steps->pluck('stage')->all());
        $this->assertContains('scope', $run->steps->pluck('stage')->all());
        $this->assertSame(1, AgentRun::count(), 'Không để lại phiên tạm');
    }

    public function test_khong_loi_thi_held_khong_goi_ai_khong_tao_phien_ban(): void
    {
        $a = $this->article();
        $ai = $this->fake();
        $run = $this->runIt($a, $ai);

        $this->assertSame('held', $run->status);
        $this->assertSame('Không tìm thấy lỗi cần sửa.', $run->outcome_reason);
        $this->assertSame(0, $ai->meta + $ai->sections + $ai->articles + $ai->outlines);
        $this->assertSame(1, $a->versions()->count());
        $this->assertSame('approved', $a->refresh()->workflow_status);
    }

    public function test_diem_sau_thap_hon_thi_luu_nhap_nhung_khong_vao_hang_cho_duyet(): void
    {
        $a = $this->article('refresh', $this->longTitle());
        $ai = $this->fake(['meta' => ['seo_title' => 'Ngắn', 'meta_description' => 'Ngắn']]);
        $run = $this->runIt($a, $ai);

        $this->assertSame('held', $run->status);
        $this->assertMatchesRegularExpression('/\d+ → \d+/', (string) $run->outcome_reason);
        $this->assertSame('drafting', $run->finalVersion->review_status);
        $this->assertSame('approved', $a->refresh()->workflow_status);
        $this->assertSame(2, $a->versions()->count());
    }

    public function test_vuot_ngan_sach_dung_va_giu_ban_cu(): void
    {
        $a = $this->article('refresh', $this->longTitle());
        $ai = $this->fake();
        $run = $this->runIt($a, $ai, new RunPolicy(budgetVnd: 0.0));

        $this->assertSame('blocked', $run->status);
        $this->assertSame(0, $ai->meta);
        $this->assertSame(1, $a->versions()->count());
        $this->assertSame('approved', $a->refresh()->workflow_status);
    }

    public function test_het_ngan_sach_giua_chung_thi_held_va_khong_tao_ban_moi(): void
    {
        $html = '<p>Bài giới thiệu '.self::KW.' cho sinh viên.</p><h2>Một</h2><p>'.QualityFixtures::words(300, 1).'</p><h2>Hai '.self::KW.'</h2><p>'.QualityFixtures::words(300, 2).' <img src="/m/a.jpg"></p><h2>Ba</h2><p>'.QualityFixtures::words(300, 3).'</p><h2>Bốn</h2><p>'.QualityFixtures::words(300, 4).'</p>';
        $a = $this->article('refresh', ['html_content' => $html] + $this->longTitle());
        $ai = $this->fake();
        $run = $this->runIt($a, $ai, new RunPolicy(budgetVnd: 3.0));

        $this->assertSame('held', $run->status);
        $this->assertSame([1, 0], [$ai->meta, $ai->sections]);
        $this->assertSame(1, $a->versions()->count());
    }

    public function test_ai_khong_ho_tro_partial_thi_viet_lai_va_ghi_ly_do(): void
    {
        $a = $this->article('refresh', $this->longTitle());
        $ai = new class implements AiProviderInterface
        {
            public int $articles = 0;

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['a'], 'tokens_used' => 1, 'model' => 'x'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->articles++;

                return QualityFixtures::goodAttrs($article->primary_keyword) + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'x', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
        $run = $this->runIt($a, $ai);

        $this->assertSame(1, $ai->articles);
        $this->assertSame('article_refresh', $run->job_type);
        $r = $run->steps->firstWhere('stage', 'rewrite');
        $this->assertStringContainsString('chưa hỗ trợ sửa một phần', $r->detail);
    }

    public function test_dispatcher_chon_dung_bo_chay(): void
    {
        $this->assertTrue(AgentRunDispatcher::usesRefresh($this->article('refresh')));
        $this->assertFalse(AgentRunDispatcher::usesRefresh($this->article('new')));
        $this->assertFalse(AgentRunDispatcher::usesRefresh($this->article('refresh', [], false)));

        $normal = $this->article('new', [], false);
        $run = AgentRunDispatcher::run($normal, new MockAiProvider, policy: new RunPolicy);
        $this->assertSame('article_write', $run->job_type);

        $refresh = $this->article('refresh', $this->longTitle());
        $run = AgentRunDispatcher::run($refresh, $this->fake(), policy: new RunPolicy);
        $this->assertSame('article_refresh', $run->job_type);
        $this->assertSame(2, $refresh->versions()->count());

        $empty = $this->article('refresh', [], false);
        $run = AgentRunDispatcher::run($empty, new MockAiProvider, policy: new RunPolicy);
        $this->assertSame('article_write', $run->job_type, 'kind=refresh chưa có bản: đi đường cũ');
    }
}
