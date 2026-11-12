<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityRubric;
use App\Services\Content\Quality\Rules\HeadingLeakRule;
use App\Services\Content\Quality\Rules\ImagePlaceholderRule;
use App\Services\Content\Quality\Rules\IntentSpecificityRule;
use App\Services\Content\Quality\Rules\PaddingRule;
use App\Services\Content\Quality\Rules\TextGlitchRule;
use Tests\TestCase;

/** Luật mới sau lượt chạy thử Gemini 12/11/2026; đoạn mẫu trích từ chính các bài chạy thử. Mọi luật mới là P1 (không chặn). */
class NewQualityRulesTest extends TestCase
{
    private function check(object $rule, string $html, string $kw = 'từ khóa thử'): array
    {
        $report = (new ContentQualityChecker([$rule]))->check(new ArticleVersion(['html_content' => $html, 'focus_keyword' => $kw]), new Article(['primary_keyword' => $kw]));

        return $report->failed();
    }

    public function test_tat_ca_luat_moi_co_trong_rubric_va_la_p1(): void
    {
        foreach (['PAD01', 'TXT01', 'LEAK01', 'IMG02', 'INT01'] as $code) {
            $this->assertNotNull(QualityRubric::for($code), $code);
            $this->assertContains($code, array_map(fn ($r) => $r->code(), ContentQualityChecker::defaultRules()));
        }
        foreach ([new PaddingRule, new TextGlitchRule, new HeadingLeakRule, new ImagePlaceholderRule, new IntentSpecificityRule] as $rule) {
            $f = $this->check($rule, '<p>Tóm lại, mỗiσ ca đóng vai trò cốt lõi vàqualified</p><h2>H2: x</h2><figure><figcaption>Ảnh</figcaption></figure>', 'báo giá máy');
            foreach ($f as $r) {
                $this->assertNotSame('P0', $r->severity, $r->code.' không được chặn');
            }
        }
    }

    public function test_cum_dem_ai(): void
    {
        $f = $this->check(new PaddingRule, '<p>Tóm lại, máy dập viên đóng vai trò cốt lõi trong nhà máy.</p>');
        $this->assertSame('PAD01', $f[0]->code);
        $this->assertSame([], $this->check(new PaddingRule, '<p>Chày trên hạ xuống, nén bột trong cối.</p>'));
    }

    public function test_loi_chu_dinh_lien_va_ky_tu_la(): void
    {
        foreach (['<p>Mọi thiết bị phải được hiệu chuẩn vàqualified trước khi dùng.</p>', '<p>Bao gồmmotor, hộp giảm tốc và PLC.</p>', '<p>Vệ sinh sau mỗiσ ca làm việc.</p>'] as $html) {
            $f = $this->check(new TextGlitchRule, $html);
            $this->assertSame('TXT01', $f[0]->code, $html);
        }
    }

    public function test_van_ban_tieng_viet_binh_thuong_khong_bi_bao_loi_chu(): void
    {
        $ok = '<p>Nghiêng người, nguyễn ngữ nghiệp, chuyển khuyến nghị, thuyết trình, khoảng trống, Mailchimp, HubSpot, GMP-WHO, nhà máy đạt chuẩn EU-GMP.</p>';
        $this->assertSame([], $this->check(new TextGlitchRule, $ok));
    }

    public function test_nhan_cau_truc_ro_vao_heading(): void
    {
        $f = $this->check(new HeadingLeakRule, '<h2>H1: Máy dập viên là gì và nguyên lý hoạt động chi tiết</h2><h2>H2: Cấu tạo</h2><h2>Cấu tạo cơ bản</h2>');
        $this->assertSame('LEAK01', $f[0]->code);
        $this->assertStringContainsString('2 tiêu đề', $f[0]->message);
        $this->assertSame([], $this->check(new HeadingLeakRule, '<h2>H2O trong thuốc tiêm</h2><h2>Bước 1: Chuẩn bị</h2>'));
    }

    public function test_o_anh_giu_cho_khong_co_anh(): void
    {
        $f = $this->check(new ImagePlaceholderRule, '<figure><figcaption>Ảnh minh họa do AI tạo</figcaption></figure>');
        $this->assertSame('IMG02', $f[0]->code);
        $this->assertSame([], $this->check(new ImagePlaceholderRule, '<figure><img src="http://x/a.webp" alt="a"><figcaption>Ảnh minh họa do AI tạo</figcaption></figure>'));
    }

    public function test_bai_bao_gia_khong_co_gia_bi_co_thieu_cu_the(): void
    {
        $f = $this->check(new IntentSpecificityRule, '<p>Mức đầu tư thường nằm ở phân khúc cao cấp, dao động tùy thuộc vào số lượng chày cối và tốc độ quay.</p>', 'báo giá máy dập viên công nghiệp');
        $this->assertSame('INT01', $f[0]->code);
        $this->assertStringContainsString('mức giá', $f[0]->message);
        $this->assertSame([], $this->check(new IntentSpecificityRule, '<p>Máy tâm đơn: từ 30.000.000 VNĐ đến 120.000.000 VNĐ; xoay tròn 180 triệu đồng trở lên.</p>', 'báo giá máy dập viên công nghiệp'));
    }

    public function test_bai_so_sanh_khong_ten_cu_the_bi_co(): void
    {
        $generic = '<p>Nhóm công cụ chuyên sâu cho email marketing và nurture lead. Nhóm nền tảng all-in-one tích hợp CRM. Nhóm công cụ tối ưu chi phí cho doanh nghiệp siêu nhỏ.</p>';
        $f = $this->check(new IntentSpecificityRule, $generic, 'so sánh phần mềm marketing automation cho doanh nghiệp vừa và nhỏ');
        $this->assertSame('INT01', $f[0]->code);

        $named = '<p>Chi phí khởi đầu: HubSpot tính theo liên hệ, Mailchimp có gói miễn phí, còn GetResponse tính theo số email.</p>';
        $this->assertSame([], $this->check(new IntentSpecificityRule, $named, 'so sánh phần mềm marketing automation'));
        $table = '<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></table>';
        $this->assertSame([], $this->check(new IntentSpecificityRule, $table, 'so sánh máy dập viên xoay tròn'));
    }

    public function test_bai_thong_tin_khong_bi_xet_do_cu_the(): void
    {
        $this->assertSame([], $this->check(new IntentSpecificityRule, '<p>Marketing automation là gì?</p>', 'marketing automation là gì'));
    }
}
