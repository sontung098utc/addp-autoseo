<?php

namespace Tests\Unit;

use App\Models\ArticleVersion;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityReport;
use App\Services\Content\Quality\Rules\LayoutRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutRuleTest extends TestCase
{
    use RefreshDatabase;

    private function lay(string $html): QualityReport
    {
        $v = new ArticleVersion(array_merge(QualityFixtures::goodAttrs('học bổng toàn phần'), ['html_content' => $html]));

        return (new ContentQualityChecker([new LayoutRule]))->check($v);
    }

    private function para(int $words): string
    {
        return '<p>'.implode(' ', array_fill(0, $words, 'chữ')).'</p>';
    }

    public function test_clean_layout_passes(): void
    {
        $r = $this->lay('<h2>A</h2>'.$this->para(900).'<aside class="autoseo-callout"><strong>Lưu ý</strong><p>Một câu ngắn.</p></aside><h2>B</h2><table><tr><th>X</th><th>Y</th></tr><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></table>');
        $this->assertSame([], $r->layoutGaps());
    }

    public function test_flags_too_many_callouts_lists_in_callout_and_duplicate_disclaimer(): void
    {
        $c = fn ($b) => '<aside class="autoseo-callout"><strong>T</strong>'.$b.'</aside>';
        $disc = '<p>Thông tin chỉ mang tính tham khảo, không thay thế tư vấn của bác sĩ.</p>';
        $html = $c('<p>a</p>').$c('<p>b</p>').$c('<ul><li>x</li><li>y</li></ul>').$c($disc).$c($disc);
        $gaps = implode(' | ', $this->lay($html)->layoutGaps());
        $this->assertStringContainsString('3 khung lưu ý', $gaps);
        $this->assertStringContainsString('đựng danh sách', $gaps);
        $this->assertStringContainsString('2 khung', $gaps);
    }

    public function test_medical_disclaimer_callout_is_not_counted_and_long_callout_flagged(): void
    {
        $disc = '<aside class="autoseo-callout"><p>Thông tin chỉ mang tính tham khảo, không thay thế tư vấn của bác sĩ.</p></aside>';
        $this->assertSame([], $this->lay('<aside class="autoseo-callout"><p>a</p></aside><aside class="autoseo-callout"><p>b</p></aside>'.$disc)->layoutGaps());
        $this->assertStringContainsString('quá dài', implode(' ', $this->lay('<aside class="autoseo-callout">'.$this->para(80).'</aside>')->layoutGaps()));
    }

    public function test_toc_summary_table_misuse(): void
    {
        $html = '<div class="autoseo-summary"><ul><li>a</li></ul></div><ul class="autoseo-toc"><li>a</li></ul><h2>A</h2><p>ngắn</p><table><tr><th>X</th></tr><tr><td>1</td></tr></table>';
        $gaps = implode(' | ', $this->lay($html)->layoutGaps());
        $this->assertStringContainsString('Mục lục chỉ dùng', $gaps);
        $this->assertStringContainsString('Tóm tắt nhanh chỉ dùng', $gaps);
        $this->assertStringContainsString('Bảng chỉ có 1 cột', $gaps);
    }

    public function test_lay01_is_not_blocking(): void
    {
        $this->assertFalse($this->lay('<ul class="autoseo-toc"><li>a</li></ul>')->hasBlocking());
    }
}
