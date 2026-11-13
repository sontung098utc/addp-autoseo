<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Contracts\StructuredAiInterface;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Content\CitationSupportJudge;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\Rules\CitationFigureRule;
use App\Services\Knowledge\AiJson;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** CITE02: số liệu khớp mục trích (tất định) và lượt AI kiểm nghĩa trích dẫn đi vào vòng sửa. AI GIẢ. */
class CitationSupportTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    private KnowledgeItem $kb;

    private KnowledgeItem $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        app(EmergencyStopService::class)->resetForTesting();
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $b = Brain::create(['name' => 'Học bổng', 'slug' => 'hb-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'low']]);
        $b->websites()->attach($w->id, ['priority' => 10]);
        $this->kb = KnowledgeItem::create(['brain_id' => $b->id, 'type' => 'fact', 'title' => 'Học bổng toàn phần', 'body' => 'Học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc.', 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash('a', 'x')]);
        $this->other = KnowledgeItem::create(['brain_id' => $b->id, 'type' => 'fact', 'title' => 'Hạn nộp hồ sơ', 'body' => 'Hạn nộp hồ sơ là ngày 30 tháng 6 hằng năm.', 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash('b', 'x')]);
        $this->article = Article::create(['website_id' => $w->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'planned']);
    }

    private function html(string $p): string
    {
        return QualityFixtures::goodAttrs('học bổng toàn phần')['html_content'].$p;
    }

    public function test_so_lieu_khong_co_trong_muc_duoc_trich_bi_ghi_va_so_dung_thi_dat(): void
    {
        $bad = $this->html("<p>Theo khuyến cáo, học bổng miễn 80% học phí. [KB#{$this->kb->id}]</p>");
        $r = (new ContentQualityChecker([new CitationFigureRule]))->check(new ArticleVersion(['html_content' => $bad] + QualityFixtures::goodAttrs('học bổng toàn phần')), $this->article);
        $this->assertCount(1, $r->supportGaps());
        $this->assertStringContainsString('80%', $r->supportGaps()[0]);

        $ok = $this->html("<p>Theo khuyến cáo, học bổng miễn 100% học phí. [KB#{$this->kb->id}]</p>");
        $r2 = (new ContentQualityChecker([new CitationFigureRule]))->check(new ArticleVersion(['html_content' => $ok] + QualityFixtures::goodAttrs('học bổng toàn phần')), $this->article);
        $this->assertSame([], $r2->supportGaps());
        $this->assertFalse($r2->hasBlocking());
    }

    private function fakeStructured(array $verdicts, ?\Throwable $throw = null): StructuredAiInterface
    {
        return new class($verdicts, $throw) implements StructuredAiInterface
        {
            public int $calls = 0;

            public string $lastUser = '';

            public function __construct(private array $v, private ?\Throwable $t) {}

            public function generateStructured(string $system, string $user): array
            {
                $this->calls++;
                $this->lastUser = $user;
                if ($this->t) {
                    throw $this->t;
                }

                return ['data' => ['verdicts' => $this->v], 'tokens_used' => 5, 'cost_vnd' => 2.0, 'model' => 'fake'];
            }
        };
    }

    public function test_judge_gan_co_khi_unsupported_coi_thieu_ket_luan_la_partial_va_loi_ai_la_chua_chay(): void
    {
        $html = "<p>Đây là câu A đủ dài để kiểm nghĩa trích dẫn. [KB#{$this->kb->id}]</p><p>Đây là câu B đủ dài để kiểm nghĩa trích dẫn. [KB#{$this->other->id}]</p><p>Đây là câu C đủ dài để kiểm nghĩa trích dẫn. [KB#{$this->kb->id}, KB#{$this->other->id}]</p><p>Không trích.</p>";
        $this->assertCount(3, CitationSupportJudge::pairs($html));

        $fake = $this->fakeStructured([['n' => 1, 'verdict' => 'supported'], ['n' => 2, 'verdict' => 'unsupported', 'reason' => 'mục không nói hạn nộp này']]); // đoạn 3 thiếu kết luận
        $r = (new CitationSupportJudge(new AiJson($fake)))->judge($html);
        $this->assertSame(1, $r['supported']);
        $this->assertSame(1, $r['unsupported']);
        $this->assertSame(1, $r['partial'], 'thiếu kết luận thì coi là partial, không ngầm coi là khớp');
        $this->assertFalse($r['result']->passed);
        $this->assertSame('CITE02', $r['result']->code);
        $this->assertStringContainsString('mục không nói hạn nộp này', $r['result']->message);
        $this->assertStringContainsString('<<<CAP-', $fake->lastUser, 'nội dung nằm trong khung dữ liệu có nonce');

        $err = (new CitationSupportJudge(new AiJson($this->fakeStructured([], new \RuntimeException('hết hạn mức')))))->judge($html);
        $this->assertNull($err['result'], 'lỗi AI: không có kết quả, không giả là đạt');
        $this->assertNotNull($err['error']);
    }

    /** AI giả viết + kiểm: lần chấm đầu judge bảo "unsupported", sau sửa bảo "supported". */
    public function test_ket_luan_unsupported_dua_vao_vong_sua_va_ghi_vao_buoc_cham(): void
    {
        $kb = $this->kb->id;
        $ai = new class($kb) implements AiProviderInterface, StructuredAiInterface
        {
            public array $options = [];

            public int $judged = 0;

            public function __construct(private int $kb) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Điều kiện'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->options[] = $options;
                $a = QualityFixtures::goodAttrs('học bổng toàn phần');
                $a['html_content'] .= "<p>Theo khuyến cáo, học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc. [KB#{$this->kb}]</p>";

                return $a + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }

            public function generateStructured(string $system, string $user): array
            {
                $this->judged++;

                return ['data' => ['verdicts' => [['n' => 1, 'verdict' => $this->judged === 1 ? 'unsupported' : 'supported', 'reason' => 'mục chỉ nói về học phí chung']]], 'tokens_used' => 3, 'cost_vnd' => 1.5, 'model' => 'fake-judge'];
            }
        };

        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $this->assertSame(1, $run->repair_rounds);
        $fb = (array) $ai->options[1]['feedback'];
        $this->assertTrue(collect($fb)->contains(fn ($f) => str_starts_with($f, 'CITE02:') && str_contains($f, 'mục chỉ nói về học phí chung')), 'phản hồi vòng sửa nêu đoạn và lý do');
        $grades = $run->steps->where('stage', 'grade')->values();
        $this->assertSame('done', $grades[0]->data['citation_support']['status']);
        $this->assertSame(1, $grades[0]->data['citation_support']['unsupported']);
        $this->assertSame(0, $grades[1]->data['citation_support']['unsupported']);
        $this->assertGreaterThan(0, (float) $grades[0]->cost_vnd, 'chi phí lượt kiểm được tính vào bước chấm');
    }

    public function test_pairs_doc_ca_dau_tho_va_chu_thich_so_da_luu_va_bo_qua_khoi_nguon(): void
    {
        $id = $this->kb->id;
        $saved = '<p>Câu đã lưu dạng chú thích số có đủ tám từ trở lên rồi.<sup class="autoseo-cite" data-kb="'.$id.'" title="x"><a href="#nguon-1">[1]</a></sup></p>'
            .'<div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol><li data-kb="'.$id.'">Mục</li></ol></div>';
        $p = CitationSupportJudge::pairs($saved);
        $this->assertCount(1, $p);
        $this->assertSame([$id], $p[0]['ids']);
        $this->assertStringNotContainsString('[1]', $p[0]['text']);

        // Nhãn ngắn không đưa đi kiểm nghĩa.
        $fake = $this->fakeStructured([['n' => 1, 'verdict' => 'unsupported']]);
        $r = (new CitationSupportJudge(new AiJson($fake)))->judge("<p>Nhãn ngắn [KB#{$id}]</p>");
        $this->assertSame(0, $fake->calls);
        $this->assertNull($r['result']);
    }

    public function test_diem_phat_tinh_ca_so_cho_con_lech_nen_sua_bot_cho_la_tien_bo(): void
    {
        $ref = new \ReflectionMethod(AgentRunOrchestrator::class, 'penalty');
        $ref->setAccessible(true);
        $o = new AgentRunOrchestrator($this->createMock(AiProviderInterface::class), policy: new RunPolicy);
        $res = fn (array $uncited, array $unsupported) => new \App\Services\Content\Quality\QualityReport([
            new \App\Services\Content\Quality\RuleResult('CITE01', 'P1', false, 'Mỗi khẳng định có dấu trích dẫn', 'm', 'x', 'y', null, $uncited),
            new \App\Services\Content\Quality\RuleResult('CITE02', 'P1', false, 'Mục được trích hỗ trợ đúng câu (AI kiểm lại)', 'm', 'x', 'y', null, $unsupported),
        ]);
        $before = $ref->invoke($o, $res(array_fill(0, 8, 'a'), ['x', 'y', 'z']));
        $after = $ref->invoke($o, $res(['a', 'b'], ['x']));
        $this->assertSame(2 * 3 + 8 + 3, $before);
        $this->assertLessThan($before, $after, 'cùng số luật trượt nhưng ít chỗ lệch hơn thì phải được tính là cải thiện');
    }
}
