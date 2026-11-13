<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Contracts\StructuredAiInterface;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Eval\BaselineRunner;
use App\Services\Agent\Eval\EvalJudgeAi;
use App\Services\Agent\Eval\GoldenQuery;
use App\Services\Agent\Eval\ReviewSheetExporter;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Q0.3–Q0.5: đo baseline, xuất phiếu chấm, nhập nhãn và đo giám khảo. AI GIẢ; chưa kiểm model thật. */
class EvalBaselineTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        EvalJudgeAi::reset();
        $this->site = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->tmp = sys_get_temp_dir().'/eval-'.uniqid();
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        EvalJudgeAi::reset();
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function queries(): array
    {
        return [
            new GoldenQuery('q-a', 'học bổng toàn phần', 'informational', 'low', 'x', [['id' => 'a-1', 'q' => 'Miễn bao nhiêu học phí?', 'kind' => 'so_lieu', 'needs_source' => true]]),
            new GoldenQuery('q-b', 'từ khóa chưa có bài', 'informational', 'high', 'x', [['id' => 'b-1', 'q' => 'Câu hỏi?', 'kind' => 'khai_niem', 'needs_source' => false]]),
        ];
    }

    private function articleWithVersion(string $extraHtml = ''): ArticleVersion
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'Học Bổng  Toàn Phần', 'workflow_status' => 'planned']);
        $attrs = QualityFixtures::goodAttrs('học bổng toàn phần');
        $attrs['html_content'] .= $extraHtml;

        return ArticleVersion::create($attrs + ['article_id' => $a->id, 'version' => 1, 'review_status' => 'drafting']);
    }

    private function judgeAi(): StructuredAiInterface
    {
        return new class implements StructuredAiInterface
        {
            public function generateStructured(string $system, string $user): array
            {
                return ['data' => str_contains($system, 'câu hỏi bắt buộc')
                    ? ['items' => [['n' => 1, 'status' => 'evasive', 'quote' => '']]]
                    : ['verdicts' => [['n' => 1, 'verdict' => 'unsupported', 'reason' => 'nguồn không nói']]],
                    'tokens_used' => 1, 'cost_vnd' => 0.5, 'model' => 'fake-judge'];
            }
        };
    }

    public function test_che_do_existing_do_bai_da_co_va_ghi_ro_tu_khoa_chua_co_bai(): void
    {
        $this->articleWithVersion('<p>Học bổng miễn 100% học phí cho sinh viên xuất sắc theo quy định.</p>');
        $r = (new BaselineRunner(null))->existing($this->queries());
        $this->assertSame('existing', $r['mode']);
        $this->assertSame('measured', $r['rows'][0]['status']);
        $this->assertSame('no_article', $r['rows'][1]['status']);
        $this->assertSame(1, $r['summary']['all']['n']);
        $this->assertNull($r['summary']['all']['intent_coverage'], 'Không có giám khảo AI thì độ phủ ý định không được thành số 0.');
        $this->assertSame(1, $r['summary']['skipped']['no_article']);
        $this->assertSame(1, $r['summary']['all']['no_sources']);
        $this->assertStringContainsString('chưa chạy', BaselineRunner::markdown($r));
    }

    public function test_co_giam_khao_thi_do_phu_y_dinh_vao_tong_hop(): void
    {
        $this->articleWithVersion('<p>Học bổng miễn 100% học phí cho sinh viên xuất sắc theo quy định.</p>');
        $r = (new BaselineRunner($this->judgeAi(), fn () => null))->existing($this->queries());
        $this->assertSame(0.0, $r['summary']['all']['intent_coverage']);
        $this->assertSame(1, $r['summary']['all']['intent_n']);
        $this->assertSame(0.0, $r['summary']['low']['intent_coverage']);
    }

    public function test_che_do_generate_tao_bai_chay_luong_hien_tai_roi_do(): void
    {
        $ai = new class implements AiProviderInterface
        {
            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Mở đầu', 'Nội dung'], 'tokens_used' => 10, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                return QualityFixtures::goodAttrs($article->primary_keyword) + ['tokens_used' => 50, 'cost_vnd' => 100.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
        $r = (new BaselineRunner(null))->generate([$this->queries()[0]], $this->site, $ai);
        $this->assertSame('generate', $r['mode']);
        $row = $r['rows'][0];
        $this->assertSame('measured', $row['status']);
        $this->assertNotNull($row['run']['id']);
        $this->assertSame(1, Article::where('website_id', $this->site->id)->count());
        $this->assertSame('informational', Article::first()->search_intent);
        $this->assertIsInt($row['metrics']['hygiene']['score']);
    }

    public function test_phieu_cham_dua_noi_dung_bai_vao_json_an_toan_va_dung_cau_truc(): void
    {
        $kb = KnowledgeItem::create(['type' => 'fact', 'title' => 'Mức miễn', 'body' => 'Học bổng toàn phần miễn 100% học phí.', 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash('m', 'x'), 'website_id' => $this->site->id]);
        $v = $this->articleWithVersion('<p>Học bổng miễn 100% học phí cho sinh viên xuất sắc theo quy định<sup data-kb="'.$kb->id.'">[1]</sup>.</p>'
            .'<p>Nội dung độc hại </script><script>alert(1)</script> cần được vô hiệu.</p><p>Mức hỗ trợ khoảng 50 triệu đồng mỗi năm cho sinh viên.</p>');
        $m = (new \App\Services\Agent\Eval\ArticleMetrics($this->judgeAi(), null, fn () => null))->measure($v, $v->article, $this->queries()[0]);
        $html = (new ReviewSheetExporter)->html([['version' => $v, 'metrics' => $m, 'query' => $this->queries()[0]]], 'Phiếu thử');

        $this->assertStringNotContainsString('</script><script>alert(1)', $html, 'Nội dung bài không được thoát khỏi khối dữ liệu của phiếu.');
        $this->assertStringContainsString('autoseo-eval-labels/1', $html);
        preg_match('/<script id="data" type="application\/json">(.*?)<\/script>/s', $html, $mm);
        $d = json_decode($mm[1], true);
        $this->assertSame(ReviewSheetExporter::SCHEMA, $d['schema']);
        $e = $d['entries'][0];
        $this->assertSame($v->id, $e['version_id']);
        $this->assertCount(1, $e['must_answer']);
        $this->assertSame('evasive', $e['must_answer'][0]['ai']);
        $this->assertCount(1, $e['pairs']);
        $this->assertSame($kb->id, $e['pairs'][0]['sources'][0]['id']);
        $this->assertSame('unsupported', $e['pairs'][0]['ai']);
        $this->assertNotEmpty($e['uncited'], 'Câu "50 triệu đồng" chưa dẫn nguồn phải được liệt kê.');
    }

    public function test_phieu_cham_van_liet_ke_cau_hoi_bat_buoc_khi_chua_chay_giam_khao_ai(): void
    {
        $v = $this->articleWithVersion('<p>Học bổng miễn 100% học phí cho sinh viên xuất sắc theo quy định.</p>');
        $q = $this->queries()[0];
        $m = (new \App\Services\Agent\Eval\ArticleMetrics(null))->measure($v, $v->article, $q);
        $e = (new ReviewSheetExporter)->entry(['version' => $v, 'metrics' => $m, 'query' => $q]);
        $this->assertCount(1, $e['must_answer']);
        $this->assertSame('', $e['must_answer'][0]['ai']);
        $this->assertSame('Miễn bao nhiêu học phí?', $e['must_answer'][0]['q']);
    }

    public function test_lenh_baseline_sheet_labels_chay_tron_chuoi_va_do_giam_khao(): void
    {
        $kb = KnowledgeItem::create(['type' => 'fact', 'title' => 'Mức miễn', 'body' => 'Học bổng toàn phần miễn 100% học phí.', 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash('m2', 'x'), 'website_id' => $this->site->id]);
        $this->articleWithVersion('<p>Học bổng miễn 100% học phí cho sinh viên xuất sắc theo quy định<sup data-kb="'.$kb->id.'">[1]</sup>.</p>');
        $set = $this->tmp.'/set.json';
        file_put_contents($set, json_encode(['queries' => [['id' => 'q-a', 'keyword' => 'học bổng toàn phần', 'intent' => 'informational', 'risk' => 'low', 'domain' => 'x',
            'must_answer' => [['id' => 'a-1', 'q' => 'Miễn bao nhiêu học phí?', 'kind' => 'so_lieu', 'needs_source' => true]]]]], JSON_UNESCAPED_UNICODE));

        $this->assertSame(0, Artisan::call('agent:eval-baseline', ['--set' => $set, '--no-ai' => true, '--out' => $this->tmp]));
        $json = glob($this->tmp.'/baseline-*.json')[0];
        $this->assertFileExists(str_replace('.json', '.md', $json));
        $rep = json_decode(file_get_contents($json), true);
        $this->assertFalse($rep['judge_ai']);
        $this->assertSame('measured', $rep['rows'][0]['status']);

        EvalJudgeAi::fake($this->judgeAi());
        $this->assertSame(0, Artisan::call('agent:eval-baseline', ['--set' => $set, '--out' => $this->tmp]));
        $withAi = glob($this->tmp.'/baseline-*.json');
        rsort($withAi);
        $rep2 = json_decode(file_get_contents($withAi[0]), true);
        $this->assertTrue($rep2['judge_ai']);

        $sheet = $this->tmp.'/phieu.html';
        $this->assertSame(0, Artisan::call('agent:eval-sheet', ['--versions' => (string) ArticleVersion::first()->id, '--out' => $sheet]));
        $this->assertFileExists($sheet);

        $d = json_decode((preg_match('/<script id="data" type="application\/json">(.*?)<\/script>/s', file_get_contents($sheet), $m) ? $m[1] : '{}'), true);
        $e = $d['entries'][0];
        $labels = ['schema' => ReviewSheetExporter::SCHEMA, 'reviewer' => 'Dược sĩ thử', 'labels' => [[
            'version_id' => $e['version_id'], 'verdict' => 'sua_nhe',
            'must_answer' => [['id' => 'a-1', 'ai' => $e['must_answer'][0]['ai'], 'human' => 'co']],
            'pairs' => [['key' => $e['pairs'][0]['key'], 'ai' => $e['pairs'][0]['ai'], 'human' => 'dung']],
        ]]];
        $lf = $this->tmp.'/nhan.json';
        file_put_contents($lf, json_encode($labels));
        $this->assertSame(0, Artisan::call('agent:eval-labels', ['file' => $lf, '--out' => $this->tmp.'/labels']));
        $out = Artisan::output();
        $this->assertStringContainsString('Dược sĩ thử', $out);
        $this->assertStringContainsString('n=1', $out);
        $this->assertNotEmpty(glob($this->tmp.'/labels/*.calibration.json'));
    }

    public function test_tep_nhan_sai_schema_bi_tu_choi(): void
    {
        $lf = $this->tmp.'/sai.json';
        file_put_contents($lf, json_encode(['schema' => 'khac', 'labels' => []]));
        $this->assertSame(1, Artisan::call('agent:eval-labels', ['file' => $lf, '--out' => $this->tmp.'/x']));
    }

    public function test_generate_khong_co_yes_thi_khong_chay_va_khong_ghi_db(): void
    {
        $this->assertSame(1, Artisan::call('agent:eval-baseline', ['--mode' => 'generate', '--website' => $this->site->id, '--limit' => 1, '--out' => $this->tmp]));
        $this->assertStringContainsString('--yes', Artisan::output());
        $this->assertSame(0, Article::count());
    }
}
