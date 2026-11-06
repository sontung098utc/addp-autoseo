<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityReport;
use App\Services\Content\Quality\RuleResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityRulesTest extends TestCase
{
    use RefreshDatabase;

    private const KW = 'học bổng toàn phần';

    private function check(array $attrs = [], ?Article $article = null): QualityReport
    {
        $v = new ArticleVersion(array_merge(QualityFixtures::goodAttrs(self::KW), $attrs));

        return (new ContentQualityChecker)->check($v, $article);
    }

    /** @return list<RuleResult> */
    private function of(QualityReport $r, string $code, ?string $label = null): array
    {
        return array_values(array_filter($r->results, fn ($x) => $x->code === $code && ($label === null || $x->label === $label)));
    }

    private function one(QualityReport $r, string $code, ?string $label = null): RuleResult
    {
        $x = $this->of($r, $code, $label);
        $this->assertNotEmpty($x, "Không có kết quả $code $label");

        return $x[0];
    }

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    public function test_good_article_has_no_failures_in_required_rules(): void
    {
        $r = $this->check();
        $this->assertSame([], array_map(fn ($x) => $x->code . ':' . $x->label, $r->failed()));
        $this->assertFalse($r->hasBlocking());
        $this->assertSame(['P0' => 0, 'P1' => 0, 'P2' => 0], $r->counts());
    }

    public function test_len01_boundaries(): void
    {
        foreach ([[399, false, 'P0'], [400, false, 'P1'], [799, false, 'P1'], [800, true, null]] as [$n, $ok, $sev]) {
            $x = $this->one($this->check(['html_content' => '<h2>a</h2><h2>b</h2><p>' . QualityFixtures::words($n - 2) . '</p>']), 'LEN01');
            $this->assertSame($ok, $x->passed, "n=$n");
            if (! $ok) {
                $this->assertSame($sev, $x->severity, "n=$n");
            }
            $this->assertSame("$n từ", $x->measured);
        }
        $x = $this->one($this->check(['html_content' => '<p>' . QualityFixtures::words(620) . '</p>']), 'LEN01');
        $this->assertStringContainsString('Bài có 620 từ, tối thiểu 800', $x->message);
    }

    public function test_head01_rules(): void
    {
        $none = $this->check(['html_content' => '<p>' . QualityFixtures::words(900) . '</p>']);
        $this->assertSame('P0', $this->one($none, 'HEAD01')->severity);
        $this->assertTrue($none->hasBlocking());

        $oneH2 = $this->check(['html_content' => '<h2>a</h2><p>x</p>']);
        $this->assertFalse($this->one($oneH2, 'HEAD01', 'Số H2')->passed);

        $twoH1 = $this->check(['html_content' => '<h1>a</h1><h1>b</h1><h2>c</h2><h2>d</h2>']);
        $this->assertFalse($this->one($twoH1, 'HEAD01', 'Số H1')->passed);
        $oneH1 = $this->check(['html_content' => '<h1>a</h1><h2>c</h2><h2>d</h2>']);
        $this->assertTrue($this->one($oneH1, 'HEAD01', 'Số H1')->passed);

        $skip = $this->check(['html_content' => '<h2>a</h2><h4>b</h4><h2>c</h2>']);
        $x = $this->one($skip, 'HEAD01', 'Nhảy cấp heading');
        $this->assertFalse($x->passed);
        $this->assertStringContainsString('H2 → H4', $x->excerpt);
        $this->assertTrue($this->one($this->check(['html_content' => '<h2>a</h2><h3>b</h3><h4>x</h4><h2>c</h2>']), 'HEAD01', 'Nhảy cấp heading')->passed);
        $this->assertFalse($this->one($this->check(['html_content' => '<h3>a</h3><h2>c</h2><h2>d</h2>']), 'HEAD01', 'Nhảy cấp heading')->passed);
    }

    public function test_kw01_items_and_case_insensitive(): void
    {
        $r = $this->check(['seo_title' => 'HỌC BỔNG   TOÀN PHẦN 2026']);
        $this->assertTrue($this->one($r, 'KW01', 'Từ khóa trong tiêu đề SEO')->passed);

        $r = $this->check(['seo_title' => 'Điều kiện nhận học bổng']);
        $this->assertFalse($this->one($r, 'KW01', 'Từ khóa trong tiêu đề SEO')->passed);
        $this->assertFalse($r->hasBlocking());

        $r = $this->check(['html_content' => '<h2>Mục một</h2><h2>Mục hai</h2><p>' . QualityFixtures::words(150) . ' học bổng toàn phần ' . QualityFixtures::words(700, 5) . '</p>']);
        $this->assertFalse($this->one($r, 'KW01', 'Từ khóa trong 100 từ đầu')->passed);
        $this->assertFalse($this->one($r, 'KW01', 'Từ khóa trong heading')->passed);
        $this->assertTrue($this->one($r, 'KW01', 'Từ khóa trong bài')->passed);

        $absent = $this->check(['html_content' => '<h2>a</h2><h2>b</h2><p>' . QualityFixtures::words(900) . '</p>']);
        $x = $this->one($absent, 'KW01', 'Từ khóa trong bài');
        $this->assertSame('P0', $x->severity);
        $this->assertFalse($x->passed);
        $this->assertTrue($absent->hasBlocking());
    }

    public function test_kw01_falls_back_to_article_keyword_and_flags_stuffing(): void
    {
        $a = new Article(['primary_keyword' => 'visa du học']);
        $r = $this->check(['focus_keyword' => null, 'html_content' => '<h2>visa du học</h2><h2>b</h2><p>' . str_repeat('visa du học ', 40) . QualityFixtures::words(60) . '</p>'], $a);
        $x = $this->one($r, 'KW01', 'Mật độ từ khóa');
        $this->assertFalse($x->passed);
        $this->assertSame('P2', $x->severity);
        $this->assertStringContainsString('nhồi', $x->message);

        $this->assertFalse($this->one($this->check(['focus_keyword' => null]), 'KW01')->passed, 'không có từ khóa ở cả hai nơi');
    }

    public function test_meta01_boundaries(): void
    {
        foreach ([[60, true], [61, false]] as [$n, $ok]) {
            $this->assertSame($ok, $this->one($this->check(['seo_title' => 'học bổng toàn phần ' . str_repeat('á', $n - 19)]), 'META01', 'Tiêu đề SEO')->passed, "title $n");
        }
        foreach ([[119, false], [120, true], [160, true], [161, false]] as [$n, $ok]) {
            $x = $this->one($this->check(['meta_description' => str_repeat('ê', $n)]), 'META01', 'Meta description');
            $this->assertSame($ok, $x->passed, "meta $n");
            $this->assertSame("$n ký tự", $x->measured);
        }
        $none = $this->one($this->check(['meta_description' => null]), 'META01', 'Meta description');
        $this->assertFalse($none->passed);
        $this->assertSame('P1', $none->severity);
        $this->assertTrue($this->one($this->check(['slug' => 'hoc-bong-2026']), 'META01', 'Slug')->passed);
        $bad = $this->one($this->check(['slug' => 'Học Bổng']), 'META01', 'Slug');
        $this->assertFalse($bad->passed);
        $this->assertSame('P2', $bad->severity);
    }

    public function test_img01_inline_and_featured(): void
    {
        $r = $this->check(['html_content' => '<h2>a</h2><h2>b</h2><p>x <img src="/a.jpg"> <img src="/b.jpg" alt=" "> <img src="/c.jpg" alt="Sinh viên"></p>']);
        $x = $this->one($r, 'IMG01');
        $this->assertFalse($x->passed);
        $this->assertSame('2 ảnh thiếu alt', $x->measured);

        $site = $this->site();
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => self::KW]);
        $v = QualityFixtures::version($a, 1, 'needs_review');
        $m = \App\Models\MediaAsset::create(['file_path' => 'x.webp', 'file_name' => 'x.webp', 'original_name' => 'anh-bia.webp', 'mime_type' => 'image/webp', 'file_size' => 1, 'sha256' => str_repeat('a', 64)]);
        $v->mediaAssets()->attach($m->id, ['is_featured' => true, 'position' => 0]);
        $this->assertFalse($this->one((new ContentQualityChecker)->check($v), 'IMG01')->passed);
        $v->mediaAssets()->updateExistingPivot($m->id, ['custom_alt' => 'Lễ trao học bổng']);
        $this->assertTrue($this->one((new ContentQualityChecker)->check($v->fresh()), 'IMG01')->passed);
    }

    public function test_safe01_detects_script_handlers_js_urls_iframe(): void
    {
        foreach (['<script>alert(1)</script>', '<img src="x" onerror="alert(1)" alt="a">', '<a href=" JavaScript:alert(1)">x</a>', '<iframe src="https://e.vn"></iframe>', '<p onclick="x()">a</p>'] as $bad) {
            $r = $this->check(['html_content' => '<h2>a</h2><h2>b</h2>' . $bad]);
            $this->assertFalse($this->one($r, 'SAFE01')->passed, $bad);
            $this->assertTrue($r->hasBlocking(), $bad);
            $this->assertContains('SAFE01', $r->blockingCodes());
        }
        $this->assertTrue($this->one($this->check(['html_content' => '<h2>a</h2><p>Văn bản &lt;script&gt; chỉ là chữ, onerror cũng vậy.</p>']), 'SAFE01')->passed);
    }

    public function test_fact01_numbers_need_sources(): void
    {
        $with = ['html_content' => '<h2>a</h2><h2>b</h2><p>Học bổng 30 triệu đồng, tăng 15% từ năm 2026.</p>'];
        $r = $this->check($with + ['sources_cited' => []]);
        $this->assertFalse($this->one($r, 'FACT01', 'Số liệu cần nguồn')->passed);
        $this->assertSame([], array_filter($r->blocking(), fn ($x) => $x->code === 'FACT01'), 'chỉ P1 khi chưa có bản đã duyệt');
        $this->assertTrue($this->one($this->check($with + ['sources_cited' => ['https://nguon.vn']]), 'FACT01', 'Số liệu cần nguồn')->passed);
        $this->assertTrue($this->one($this->check(['html_content' => '<h2>a</h2><h2>b</h2><p>Không có số liệu.</p>', 'sources_cited' => []]), 'FACT01')->passed);
    }

    public function test_fact01_changed_numbers_vs_approved_version(): void
    {
        $a = Article::create(['website_id' => $this->site()->id, 'primary_keyword' => self::KW]);
        $old = '<p>Học bổng trị giá 30 triệu đồng cho sinh viên xuất sắc.</p>';
        $new = '<p>Học bổng trị giá 45 triệu đồng cho sinh viên xuất sắc.</p>';
        QualityFixtures::version($a, 1, 'approved', ['html_content' => $old . QualityFixtures::goodHtml(), 'sources_cited' => ['https://a.vn/nguon']]);

        $make = fn (array $x) => QualityFixtures::version($a, 2 + random_int(0, 10000), 'needs_review', $x + ['html_content' => $new . QualityFixtures::goodHtml()]);
        $label = fn ($v) => $this->one((new ContentQualityChecker)->check($v), 'FACT01', 'Thay đổi số liệu so với v1 đã duyệt');

        $x = $label($make(['sources_cited' => []]));
        $this->assertFalse($x->passed);
        $this->assertSame('P0', $x->severity);
        $this->assertStringContainsString('30 triệu → 45 triệu', $x->excerpt);
        $this->assertFalse($label($make(['sources_cited' => ['https://a.vn/nguon']]))->passed, 'nguồn không đổi');
        $this->assertTrue($label($make(['sources_cited' => ['https://a.vn/nguon', 'https://moi.vn/quyet-dinh']]))->passed);

        $same = QualityFixtures::version($a, 20000, 'needs_review', ['html_content' => $old . QualityFixtures::goodHtml()]);
        $this->assertTrue($label($same)->passed, 'không đổi số');
    }

    public function test_dup01_detects_overlap_and_ignores_self_and_other_sites(): void
    {
        $site = $this->site();
        $a1 = Article::create(['website_id' => $site->id, 'primary_keyword' => self::KW]);
        $base = QualityFixtures::words(500, 77);
        $other = QualityFixtures::version($a1, 1, 'approved', ['html_content' => '<h2>a</h2><h2>b</h2><p>' . $base . '</p>']);

        $a2 = Article::create(['website_id' => $site->id, 'primary_keyword' => self::KW]);
        // ~72% từ giống ⇒ Jaccard shingle ≈ 0.56
        $half = implode(' ', array_slice(explode(' ', $base), 0, 360)) . ' ' . QualityFixtures::words(140, 78);
        $dup = QualityFixtures::version($a2, 1, 'needs_review', ['html_content' => '<h2>a</h2><h2>b</h2><p>' . $half . '</p>']);
        $x = $this->one((new ContentQualityChecker)->check($dup), 'DUP01');
        $this->assertFalse($x->passed);
        $this->assertSame('P0', $x->severity);
        $this->assertStringContainsString("Bài #{$a1->id}", $x->excerpt);
        $pct = (int) $x->measured;
        $this->assertGreaterThanOrEqual(50, $pct);
        $this->assertLessThan(100, $pct);

        // P1: Jaccard ≈ 0.33
        $mid = implode(' ', array_slice(explode(' ', $base), 0, 250)) . ' ' . QualityFixtures::words(250, 79);
        $a3 = Article::create(['website_id' => $site->id, 'primary_keyword' => self::KW]);
        $v3 = QualityFixtures::version($a3, 1, 'needs_review', ['html_content' => '<h2>a</h2><h2>b</h2><p>' . $mid . '</p>']);
        $x = $this->one((new ContentQualityChecker)->check($v3), 'DUP01');
        $this->assertSame('P1', $x->severity, $x->measured);
        $this->assertFalse($x->passed);

        // không so với chính bài (các bản khác của cùng bài): bỏ các bài trùng ở trên để chỉ còn bài $a1
        ArticleVersion::whereIn('article_id', [$a2->id, $a3->id])->delete();
        $v1b = QualityFixtures::version($a1, 2, 'needs_review', ['html_content' => $other->html_content]);
        $this->assertTrue($this->one((new ContentQualityChecker)->check($v1b), 'DUP01')->passed);

        // khác website thì không so
        $site2 = Website::create(['name' => 'S2', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a4 = Article::create(['website_id' => $site2->id, 'primary_keyword' => self::KW]);
        $v4 = QualityFixtures::version($a4, 1, 'needs_review', ['html_content' => $other->html_content]);
        $this->assertTrue($this->one((new ContentQualityChecker)->check($v4), 'DUP01')->passed);
    }

    public function test_faq01(): void
    {
        $this->assertTrue($this->one($this->check(['faq_schema' => []]), 'FAQ01')->passed);
        $this->assertTrue($this->one($this->check(['faq_schema' => [['question' => 'A?', 'answer' => 'B']]]), 'FAQ01')->passed);
        $x = $this->one($this->check(['faq_schema' => [['question' => 'A?', 'answer' => ''], ['question' => '', 'answer' => 'x']]]), 'FAQ01');
        $this->assertFalse($x->passed);
        $this->assertSame('P2', $x->severity);
        $this->assertSame('2 mục lỗi', $x->measured);
    }

    public function test_broken_and_empty_html_do_not_throw(): void
    {
        foreach (['', '<p>chưa đóng <b>thẻ <h2>x', '<<>>&&&;<div', "\xC3\x28 lỗi utf8", '<h2>Chào</h2' . str_repeat('<div>', 500)] as $html) {
            $r = $this->check(['html_content' => $html]);
            $this->assertInstanceOf(QualityReport::class, $r);
            $this->assertNotEmpty($r->results);
        }
        $this->assertTrue($this->check(['html_content' => ''])->hasBlocking());
        $r = (new ContentQualityChecker)->check(new ArticleVersion(['html_content' => null]));
        $this->assertTrue($r->hasBlocking());
    }
}
