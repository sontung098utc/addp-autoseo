<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingHandoverTest extends TestCase
{
    public function test_prompt_v1_1_exists_and_contains_pilot_refinements(): void
    {
        $promptPath = base_path('resources/prompts/v1.1-seo-vietnamese-article.prompt.md');

        $this->assertFileExists($promptPath);

        $content = file_get_contents($promptPath);

        // Kiểm tra phiên bản v1.1 và các cải tiến sau pilot
        $this->assertStringContainsString('v1.1-seo-vietnamese-article', $content);
        $this->assertStringContainsString('800 từ', $content, 'Prompt phải yêu cầu tối thiểu 800 từ (LEN01)');
        $this->assertStringContainsString('tối thiểu 3 thẻ đề mục `<h2>`', $content, 'Prompt phải yêu cầu >= 3 H2 (HEAD01)');
        $this->assertStringContainsString('tối thiểu 2 thẻ đề mục `<h3>`', $content, 'Prompt phải yêu cầu >= 2 H3 (HEAD01)');
        $this->assertStringContainsString('TUYỆT ĐỐI KHÔNG sử dụng thẻ `<h1>`', $content, 'Prompt phải cấm H1 trong body');
        $this->assertStringContainsString('1.0% đến 2.5%', $content, 'Prompt phải chuẩn hóa mật độ từ khóa (KW01)');
        $this->assertStringContainsString('tối đa 60 ký tự', $content, 'Prompt phải giới hạn seo_title (META01)');
        $this->assertStringContainsString('130 đến 155 ký tự', $content, 'Prompt phải giới hạn meta_description (META02)');
        $this->assertStringContainsString('Lưu ý y khoa quan trọng', $content, 'Prompt phải có tuyên bố miễn trừ y tế (SAFE01)');
        $this->assertStringContainsString('BANNED PHRASES', $content, 'Prompt phải có danh sách từ ngữ AI sáo rỗng bị cấm');
        $this->assertStringContainsString('faq_schema', $content);
        $this->assertStringContainsString('sources_cited', $content);
    }

    public function test_marketing_user_manual_exists_and_contains_all_core_sections(): void
    {
        $manualPath = base_path('docs/handover/HUONG_DAN_MARKETING.md');

        $this->assertFileExists($manualPath);

        $content = file_get_contents($manualPath);

        // Kiểm tra 9 phần tài liệu bắt buộc
        $this->assertStringContainsString('1. GIỚI THIỆU TỔNG QUAN & PHÂN QUYỀN NGƯỜI DÙNG', $content);
        $this->assertStringContainsString('2. QUẢN LÝ WEBSITE & KẾT NỐI CMS (WORDPRESS & MAGENTO 2)', $content);
        $this->assertStringContainsString('3. NGHIÊN CỨU TỪ KHÓA & GOM CỤM CHỦ ĐỀ', $content);
        $this->assertStringContainsString('4. BIÊN TẬP BÀI VIẾT VỚI AI & BỘ 9 QUY TẮC CHẤT LƯỢNG SEO', $content);
        $this->assertStringContainsString('5. QUY TRÌNH DUYỆT BÀI & SO SÁNH PHIÊN BẢN (PARAGRAPHDIFF)', $content);
        $this->assertStringContainsString('6. XẾP LỊCH XUẤT BẢN & TÍNH NĂNG "ĐĂNG NGAY" (PUBLISH NOW)', $content);
        $this->assertStringContainsString('7. XỬ LÝ SỰ CỐ XUẤT BẢN & CƠ CHẾ ĐỐI SOÁT (NEEDS_RECONCILE)', $content);
        $this->assertStringContainsString('8. NÚT DỪNG KHẨN CẤP TOÀN HỆ THỐNG (EMERGENCY STOP KILL SWITCH)', $content);
        $this->assertStringContainsString('9. BÁO CÁO & GIÁM SÁT CHI PHÍ SỬ DỤNG AI (TOKEN & VNĐ)', $content);

        // Kiểm tra các nội dung nghiệp vụ then chốt
        $this->assertStringContainsString('Writer', $content);
        $this->assertStringContainsString('Approver', $content);
        $this->assertStringContainsString('Admin', $content);
        $this->assertStringContainsString('Application Password', $content);
        $this->assertStringContainsString('Company_BlogApi', $content);
        $this->assertStringContainsString('ParagraphDiff', $content);
        $this->assertStringContainsString('publication_key', $content);
        $this->assertStringContainsString('needs_reconcile', $content);
        $this->assertStringContainsString('Đăng ngay', $content);
        $this->assertStringContainsString('HỆ THỐNG ĐANG DỪNG XUẤT BẢN KHẨN CẤP', $content);
    }

    public function test_ac13_acceptance_document_exists_and_contains_all_criteria(): void
    {
        $acceptancePath = base_path('docs/handover/BIEN_BAN_NGHIEM_THU_AC13.md');

        $this->assertFileExists($acceptancePath);

        $content = file_get_contents($acceptancePath);

        // Kiểm tra Hợp đồng bằng chứng 4 mức
        $this->assertStringContainsString('Honest Evidence Contract', $content);
        $this->assertStringContainsString('Mức 1: Đã viết code', $content);
        $this->assertStringContainsString('Mức 2: Test giả lập', $content);
        $this->assertStringContainsString('Mức 3: Chạy thử Staging/Pilot', $content);
        $this->assertStringContainsString('Mức 4: Người thật nghiệm thu', $content);

        // Kiểm tra toàn bộ 13 tiêu chí AC01 - AC13
        for ($i = 1; $i <= 13; $i++) {
            $code = sprintf('AC%02d', $i);
            $this->assertStringContainsString($code, $content, "Biên bản nghiệm thu phải có tiêu chí {$code}");
        }

        // Kiểm tra kết quả pilot 20 bài viết
        $this->assertStringContainsString('20 bài viết', $content);
        $this->assertStringContainsString('10 bài lên WordPress, 10 bài lên Magento 2', $content);
        $this->assertStringContainsString('100%', $content);

        // Kiểm tra chữ ký hai bên
        $this->assertStringContainsString('ĐẠI DIỆN ĐỘI NGŨ KỸ THUẬT', $content);
        $this->assertStringContainsString('ĐẠI DIỆN PHÒNG MARKETING & SEO', $content);
    }
}
