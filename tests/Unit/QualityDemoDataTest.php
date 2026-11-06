<?php

namespace Tests\Unit;

use App\Filament\Support\Demo\QualityDemoData;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use App\Services\Content\Quality\ContentQualityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class QualityDemoDataTest extends TestCase
{
    use RefreshDatabase;

    private function codes(int $articleId): array
    {
        $v = ArticleVersion::where('article_id', $articleId)->orderByDesc('version')->first();

        return array_map(fn ($r) => $r->code . ':' . $r->severity, (new ContentQualityChecker)->check($v)->failed());
    }

    public function test_seed_scenarios_produce_expected_results_from_real_content(): void
    {
        $ids = QualityDemoData::seed();

        $good = (new ContentQualityChecker)->check(ArticleVersion::where('article_id', $ids['1 bài tốt'])->first());
        $this->assertSame([], array_map(fn ($r) => $r->code, $good->failed()));

        $short = $this->codes($ids['2 bài ngắn thiếu từ khóa']);
        foreach (['LEN01:P0', 'HEAD01:P0', 'KW01:P0', 'META01:P1'] as $c) {
            $this->assertContains($c, $short);
        }
        $this->assertContains('DUP01:P0', $this->codes($ids['3b bài trùng bài gốc']));
        $this->assertContains('FACT01:P0', $this->codes($ids['4 đổi số liệu không nguồn']));
        $this->assertContains('SAFE01:P0', $this->codes($ids['5 chứa script']));
    }

    public function test_seed_is_idempotent_and_purge_only_removes_samples(): void
    {
        $site = Website::create(['name' => 'Thật', 'cms_type' => 'wordpress', 'base_url' => 'https://t.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $real = Article::create(['website_id' => $site->id, 'primary_keyword' => 'bài thật', 'workflow_status' => 'planned']);

        QualityDemoData::seed();
        $n = Article::count();
        QualityDemoData::seed();
        $this->assertSame($n, Article::count());
        $this->assertSame(1, Article::where('primary_keyword', 'not like', '[Mẫu] %')->count());

        QualityDemoData::purge();
        $this->assertSame([$real->id], Article::pluck('id')->all());
        $this->assertSame(0, ArticleVersion::count());
        $this->assertSame(1, Website::count());
        QualityDemoData::purge(); // lần hai không lỗi
    }

    public function test_blocked_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        try {
            QualityDemoData::seed();
            $this->fail('phải bị chặn');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('local hoặc testing', $e->getMessage());
        }
        $this->assertSame(0, Article::count());
        $this->expectException(RuntimeException::class);
        QualityDemoData::purge();
    }
}
