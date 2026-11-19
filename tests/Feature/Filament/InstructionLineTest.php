<?php

namespace Tests\Feature\Filament;

use App\Filament\Support\Review\ReviewActions;
use App\Models\Article;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

class InstructionLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_dong_chi_dan_da_tuan_hien_o_hop_duyet_va_bang_so_tu_dung_cung_ham_dem(): void
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'needs_review', 'guidance' => 'Viết dưới 600 từ và thêm đúng một bảng.']);
        $v = QualityFixtures::version($a, 1, 'needs_review', ['html_content' => QualityFixtures::goodHtml('học bổng toàn phần', 990).'<table><tr><td>x</td></tr></table>']);
        $v->setRelation('article', $a);

        $n = \App\Services\Content\VietnameseText::bodyWordCountHtml((string) $v->html_content); // cùng hàm với cột "Số từ"
        $this->assertGreaterThan(600, $n);
        $this->assertSame("Chỉ dẫn đã tuân: dưới 600 từ ✗ ({$n}), đúng 1 bảng ✓", ReviewActions::instructionLine($v));
        $names = array_map(fn ($f) => $f->getName(), ReviewActions::approveForm(false, null, null, $v));
        $this->assertContains('instruction_line', $names);
        $this->assertNotContains('instruction_line', array_map(fn ($f) => $f->getName(), ReviewActions::approveForm(false)));
    }
}
