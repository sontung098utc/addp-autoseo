<?php

namespace Tests\Feature\Agent\Refresh;

use App\Services\Agent\Refresh\RefreshScope as S;
use App\Services\Content\Quality\QualityReport;
use App\Services\Content\Quality\RuleResult;
use PHPUnit\Framework\TestCase;

class RefreshScopeTest extends TestCase
{
    private const HTML = '<p>Mở đầu</p>'
        .'<h2>Một</h2><p>Học phí 20 triệu/năm.</p>'
        .'<h2>Hai</h2><p><img src="/media/a.jpg"> <a href="https://sai.example/x">link</a></p>'
        .'<h2>Ba</h2><p>Ba</p><h2>Bốn</h2><p>Bốn</p><h2>Năm</h2><p><img src="/m/b.jpg" alt="ok"></p>';

    private function bad(string $code, string $label = 'x', ?string $measured = null, ?string $excerpt = null, string $sev = RuleResult::P1): RuleResult
    {
        return new RuleResult($code, $sev, false, $label, 'msg', $measured, null, $excerpt);
    }

    private function ok(): RuleResult
    {
        return new RuleResult('LEN01', RuleResult::P1, true, 'ok', 'ok');
    }

    private function report(RuleResult ...$r): QualityReport
    {
        return new QualityReport(array_values($r));
    }

    public function test_only_meta01_is_meta(): void
    {
        $r = S::classify($this->report($this->ok(), $this->bad('META01', 'Tiêu đề SEO')), [], self::HTML);
        $this->assertSame(S::META, $r['scope']);
        $this->assertSame([], $r['section_indexes']);
        $this->assertStringContainsString('META01', $r['reasons'][0]);
    }

    public function test_img01_in_section_two_is_sections_index_1(): void
    {
        $r = S::classify($this->report($this->bad('IMG01', 'Alt của ảnh', '1 ảnh thiếu alt', '/media/a.jpg')), [], self::HTML);
        $this->assertSame(S::SECTIONS, $r['scope']);
        $this->assertSame([1], $r['section_indexes']);
    }

    public function test_img01_without_html_is_rewrite(): void
    {
        $r = S::classify($this->report($this->bad('IMG01', 'Alt của ảnh', '1 ảnh thiếu alt', '/media/a.jpg')));
        $this->assertSame(S::REWRITE, $r['scope']);
    }

    public function test_img01_featured_image_is_not_guessed(): void
    {
        $r = S::classify($this->report($this->bad('IMG01', 'Alt của ảnh', '1 ảnh thiếu alt', 'Ảnh đại diện: a.jpg')), [], self::HTML);
        $this->assertSame(S::REWRITE, $r['scope']);
    }

    public function test_link01_and_meta_together_is_sections_with_meta_note(): void
    {
        $r = S::classify($this->report(
            $this->bad('LINK01', 'Liên kết thuộc website', '1 liên kết lạ', 'https://sai.example/x'),
            $this->bad('META01', 'Meta description'),
        ), [], self::HTML);
        $this->assertSame(S::SECTIONS, $r['scope']);
        $this->assertSame([1], $r['section_indexes']);
        $this->assertStringContainsString('META01', implode(' ', $r['reasons']));
    }

    public function test_kb01_sentence_locates_section(): void
    {
        $r = S::classify($this->report($this->bad('KB01', 'Có thể sai số liệu so với kho kiến thức', '20 triệu', 'Học phí 20 triệu/năm.')), [], self::HTML);
        $this->assertSame(S::SECTIONS, $r['scope']);
        $this->assertSame([0], $r['section_indexes']);
    }

    public function test_len01_is_rewrite(): void
    {
        $r = S::classify($this->report($this->bad('LEN01', 'Độ dài bài', null, null, RuleResult::P0)), [], self::HTML);
        $this->assertSame(S::REWRITE, $r['scope']);
        $this->assertStringContainsString('LEN01', $r['reasons'][0]);
    }

    public function test_mixed_with_rewrite_code_is_rewrite(): void
    {
        $r = S::classify($this->report(
            $this->bad('META01', 'Tiêu đề SEO'),
            $this->bad('IMG01', 'Alt của ảnh', '1 ảnh thiếu alt', '/media/a.jpg'),
            $this->bad('BAN01', 'Từ bị cấm', null, null, RuleResult::P0),
        ), [], self::HTML);
        $this->assertSame(S::REWRITE, $r['scope']);
        $this->assertSame([], $r['section_indexes']);
    }

    public function test_no_errors_is_none(): void
    {
        $r = S::classify($this->report($this->ok()), [], self::HTML);
        $this->assertSame(S::NONE, $r['scope']);
        $this->assertNotEmpty($r['reasons']);
    }

    public function test_more_than_half_sections_is_rewrite(): void
    {
        $html = '<h2>A</h2><img src="/1.jpg"><h2>B</h2><img src="/2.jpg"><h2>C</h2><p>c</p>';
        $r = S::classify($this->report($this->bad('IMG01', 'Alt của ảnh', '2 ảnh thiếu alt', '/1.jpg; /2.jpg')), [], $html);
        $this->assertSame(S::REWRITE, $r['scope']);
        $this->assertStringContainsString('50%', $r['reasons'][0]);
    }

    public function test_exactly_half_stays_sections(): void
    {
        $html = '<h2>A</h2><img src="/1.jpg"><h2>B</h2><p>b</p>';
        $r = S::classify($this->report($this->bad('IMG01', 'Alt của ảnh', '1 ảnh thiếu alt', '/1.jpg')), [], $html);
        $this->assertSame(S::SECTIONS, $r['scope']);
    }

    public function test_audit_title_code_is_meta(): void
    {
        $r = S::classify($this->report($this->ok()), ['title_long']);
        $this->assertSame(S::META, $r['scope']);
        $this->assertStringContainsString('title_long', $r['reasons'][0]);
    }

    public function test_audit_image_no_alt_locates_with_html(): void
    {
        $r = S::classify($this->report($this->ok()), ['image_no_alt'], self::HTML);
        $this->assertSame(S::SECTIONS, $r['scope']);
        $this->assertSame([1], $r['section_indexes']);
    }

    public function test_unknown_audit_code_is_rewrite(): void
    {
        $r = S::classify($this->report($this->ok()), ['la_hoac']);
        $this->assertSame(S::REWRITE, $r['scope']);
    }

    public function test_more_failures_than_excerpt_items_is_not_guessed(): void
    {
        $r = S::classify($this->report($this->bad('IMG01', 'Alt của ảnh', '7 ảnh thiếu alt', '/media/a.jpg')), [], self::HTML);
        $this->assertSame(S::REWRITE, $r['scope']);
    }
}
