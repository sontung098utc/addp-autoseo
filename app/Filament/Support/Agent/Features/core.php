<?php

use App\Filament\Pages\AiUsagePage;
use App\Filament\Pages\AuditPage;
use App\Filament\Pages\CompetitorsPage;
use App\Filament\Pages\ContentPlanPage;
use App\Filament\Pages\KeywordsPage;
use App\Filament\Pages\SearchConsolePage;
use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\WebsiteResource;

/** Tính năng nền hiện có. Mỗi mục "active" phải có bằng chứng cụ thể (tên test/commit); chưa nghiệm thu staging thì ghi trong caveat. */
return [
    ['id' => 'website.connect', 'title' => 'Website kết nối', 'what' => 'Thêm/sửa website, lưu credential đã mã hóa, khóa trường nhạy cảm.', 'readiness' => 'active',
        'evidence' => 'Test LockSensitiveFieldsTest, WebsiteUiTest, PoliciesTest (credential bị khóa trên form; quyền theo vai trò).', 'caveat' => 'Chưa kiểm tra kết nối CMS thật trên staging.', 'task' => '0.4/0.5', 'route' => WebsiteResource::class],
    ['id' => 'content.generate', 'title' => 'Viết bài bằng AI', 'what' => 'Sinh dàn ý và bản nháp tiếng Việt, lưu thành phiên bản bài.', 'readiness' => 'active',
        'evidence' => 'Test ContentPipelineTest, AiPromptPocTest; ContentPipelineService + AiPaddingFilter + HtmlSanitizer.', 'caveat' => 'Dùng AI thật khi có khóa GOOGLE_AI_API_KEY; nếu thiếu khóa sẽ là AI GIẢ LẬP — nội dung chưa có chip nhận biết.', 'task' => '3.1', 'route' => ArticleResource::class],
    ['id' => 'content.quality', 'title' => 'Kiểm chất lượng bài', 'what' => '9 luật kiểm tra (độ dài, heading, từ khóa, meta, trùng lặp, ảnh, an toàn, số liệu, FAQ); lỗi nặng chặn duyệt.', 'readiness' => 'active',
        'evidence' => 'Test QualityRulesTest, QualityApproveTest, QualityReviewScreenTest.', 'caveat' => 'Ngưỡng chưa hiệu chỉnh trên dữ liệu thật của công ty.', 'task' => '3.5', 'route' => ArticleResource::class],
    ['id' => 'review.flow', 'title' => 'Duyệt / từ chối / xếp lịch', 'what' => 'Người duyệt xem bản bài, duyệt hoặc từ chối có lý do, rồi xếp lịch đăng.', 'readiness' => 'active',
        'evidence' => 'Test ReviewWorkflowTest, ReviewActionsTest, ReviewLayoutTest, AuditRecorderTest (luồng duyệt, nút duyệt, bố cục màn duyệt, nhật ký duyệt).', 'caveat' => 'Nhật ký duyệt ghi vào log, chưa có bảng bảng nhật ký (PR-C).', 'task' => '3.4', 'route' => ArticleResource::class],
    ['id' => 'publish.schedule', 'title' => 'Lịch xuất bản và tự đăng', 'what' => 'Lượt đăng theo giờ Việt Nam; worker đăng lên WordPress/Magento; chống đăng trùng; đối soát khi CMS không phản hồi.', 'readiness' => 'active',
        'evidence' => 'Test PublishArticleJobTest, AutopublishWorkerTest, ReconcilePublicationJobTest, WordPressConnectorTest, MagentoBridgeClientTest.', 'caveat' => 'Chưa nghiệm thu trên CMS thật (staging/UAT: Chưa chạy).', 'task' => '3.2/3.6/1.2/1.4', 'route' => PublicationResource::class],
    ['id' => 'publish.stop', 'title' => 'Dừng khẩn cấp xuất bản', 'what' => 'Dừng toàn hệ thống hoặc theo website, có lý do và người dừng; khôi phục có kiểm soát.', 'readiness' => 'active',
        'evidence' => 'Test EmergencyStopServiceTest, EmergencyStopCommandTest, AutopublishWorkerTest.', 'caveat' => 'Trạng thái dừng đang lưu trong Cache: có thể mất khi xóa cache (issue #9).', 'task' => '3.6', 'route' => PublicationResource::class],
    ['id' => 'ai.usage', 'title' => 'Chi phí AI', 'what' => 'Tổng hợp token và chi phí (VND) từ các bản bài, theo kỳ và website.', 'readiness' => 'active',
        'evidence' => 'Test AiUsageAggregatorTest, AiUsagePageTest; đọc chi phí và số token ghi trên từng bản bài.', 'caveat' => 'Chỉ tính các lần gọi đã ghi vào bản bài; chưa có nhật ký chạy AI đầy đủ.', 'task' => '6.5', 'route' => AiUsagePage::class],

    ['id' => 'audit.view', 'title' => 'Audit website', 'what' => 'Báo cáo lỗi on-page theo nhóm P0/P1/P2, xu hướng, slide-over URL.', 'readiness' => 'demo',
        'caveat' => 'Đang hiển thị dữ liệu minh họa; kết quả quét thật của crawler 1.1 đã có trong DB nhưng giao diện chưa đọc (AG-U05).', 'task' => '1.3', 'route' => AuditPage::class],
    ['id' => 'audit.rescan', 'title' => 'Quét lại website từ giao diện', 'what' => 'Bấm "Quét lại" để crawler chạy nền.', 'readiness' => 'in_development',
        'caveat' => 'Cần job nền (AG-B00, Dev1).', 'task' => 'AG-B00'],
    ['id' => 'keywords', 'title' => 'Từ khóa', 'what' => 'Nhập CSV, kiểm trùng, phân loại ý định, gom cụm.', 'readiness' => 'demo',
        'caveat' => 'Gom cụm bằng thuật toán mẫu (không dựa SERP); số liệu tìm kiếm chưa có nguồn (AG-F04).', 'task' => '2.1', 'route' => KeywordsPage::class],
    ['id' => 'plan', 'title' => 'Kế hoạch nội dung', 'what' => 'Lưới tuần, tạo bài từ cụm, chặn trùng từ khóa.', 'readiness' => 'demo',
        'caveat' => 'Dùng dữ liệu cụm minh họa; chưa gắn campaigns (PR-C).', 'task' => '2.2', 'route' => ContentPlanPage::class],
    ['id' => 'competitors', 'title' => 'Đối thủ và khoảng trống nội dung', 'what' => 'Khoảng trống chủ đề so với đối thủ.', 'readiness' => 'demo',
        'caveat' => 'Chưa có nguồn dữ liệu SERP/đối thủ thật (AG-F04).', 'task' => '5.4', 'route' => CompetitorsPage::class],
    ['id' => 'gsc', 'title' => 'Search Console', 'what' => 'Lượt hiển thị, nhấp, CTR, vị trí; cơ hội cải thiện.', 'readiness' => 'not_connected',
        'caveat' => 'Chưa kết nối Google (task 5.1). Không có số liệu thật nên chưa đo được hiệu quả SEO.', 'task' => '5.1', 'route' => SearchConsolePage::class],
];
