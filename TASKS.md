# ĐIỀU PHỐI CÔNG VIỆC VÀ TRẠNG THÁI TASK (TASKS.md)
*Dự án: Phần mềm Auto SEO & Tự động đăng bài (addp-autoseo)*

Bảng này đồng bộ trực tiếp với Master Plan (Google Sheet). Mọi cập nhật trạng thái phải đi kèm commit SHA và bằng chứng kiểm thử thực tế.

---

## MỐC V1.0: AI VIẾT BÀI, DUYỆT & ĐĂNG NHÁP WORDPRESS (128h - Hạn: 19/11/2026)

| Mã việc | Chủ nhiệm | Reviewer | Giờ dự kiến | Phụ thuộc | Nhánh đề xuất | Trạng thái | Nội dung chính |
| :---: | :---: | :---: | :---: | :---: | :--- | :---: | :--- |
| **0.1** | Dev1 | Dev2 | 8h | - | `docs/m01/0-1/dev1/scope-contract` | **Hoàn thành** | Chốt phạm vi V1, quy trình duyệt, hợp đồng dữ liệu và các quy tắc. Commit: `5f08e6e` |
| **0.2** | Dev1 | Dev2 | 24h | 0.1 | `chore/m01/0-2/dev1/infra-ci` | **Hoàn thành** | Dựng Laravel 11/12, PHP 8.2, MariaDB 10.6, Docker staging, CI workflow, backup/restore. Commit: `33c4872` |
| **0.3** | Dev2 | Dev1 | 10h | 0.1 | `feat/m01/0-3/dev2/ai-poc` | **Hoàn thành** | PoC AI tiếng Việt, Prompt v1.0, AiPaddingFilter, TokenCostCalculator và báo cáo chi phí. Commit: `d8e4454` |
| **0.4** | Dev1 | Dev2 | 22h | 0.2 | `feat/m01/0-4/dev1/auth-sites` | **Hoàn thành** | Khung Filament + Laravel: Auth, Policies 3 vai trò, quản lý website, mã hóa credential. Commit: `33c4872` |
| **0.5** | Dev2 | Dev1 | 10h | 0.1 | `feat/m01/0-5/dev2/filament` | **Hoàn thành** | Filament panel SEO Studio, StatusPresenter, khóa trường nhạy cảm P0, kế hoạch cải tạo giao diện. Commit: `9e4d748` |
| **1.2** | Dev2 | Dev1 | 16h | 0.4, 0.5 | `feat/m02/1-2/dev2/wordpress` | **Hoàn thành** | WordPress Connector: REST API + App Password; bài, ảnh, taxonomy, SEO meta adapter. Commit: `77fb0a9` |
| **3.1** | Dev1 | Dev2 | 20h | 0.3, 0.4 | `feat/m03/3-1/dev1/content-pipeline` | **Hoàn thành** | Pipeline sinh dàn ý, bài viết tiếng Việt và metadata từ tài liệu công ty. Commit: `402a440` |
| **3.4** | Dev2 | Dev1 | 10h | 0.5, 0.1 | `feat/m03/3-4/dev2/review-ui` | **Hoàn thành** | Giao diện duyệt bài: ArticleVersionResource chỉ-đọc, ParagraphDiff so sánh phiên bản, AuditRecorder tự duyệt/duyệt bài. Commit: `dd23b02` |
| **3.5** | Dev2 | Dev1 | 8h | 3.1, 3.4 | `feat/m03/3-5/dev2/quality` | **Hoàn thành** | Bộ kiểm tra chất lượng: 9 quy tắc (độ dài, heading, từ khóa, an toàn, meta, ảnh, trùng lặp), chặn P0 khi duyệt. Commit: `77fb0a9` |

---

## MỐC V1.1: TỰ ĐĂNG WORDPRESS & MAGENTO THEO LỊCH (58h - Hạn: 28/11/2026)

| Mã việc | Chủ nhiệm | Reviewer | Giờ dự kiến | Phụ thuộc | Nhánh đề xuất | Trạng thái | Nội dung chính |
| :---: | :---: | :---: | :---: | :---: | :--- | :---: | :--- |
| **1.4** | Dev1 | Dev2 | 24h | 0.4 | `feat/m02/1-4/dev1/magento-bridge` | **Hoàn thành** | Magento 2 Connector: Module `Company_BlogApi`, OAuth 1.0a, nháp, media, đối soát publication_key. Commit: `d5e5e4f` |
| **3.2** | Dev1 | Dev2 | 12h | 3.1 | `feat/m05/3-2/dev1/queue-scheduler` | **Hoàn thành** | Redis Queue, Laravel Scheduler, timeout, retry/backoff, khóa theo site, đối soát needs_reconcile. Commit: `c833b7b` |
| **3.3** | Dev1 | Dev2 | 8h | 3.1 | `feat/m03/3-3/dev1/media` | **Hoàn thành** | Thư viện ảnh công ty: MediaAsset, ImageOptimizer nén WebP, kiểm tra MIME magic bytes, UrlSafetyService chống SSRF, alt text chuẩn SEO và MediaAssetResource. Commit: `b222e68` |
| **3.6** | Dev1 | Dev2 | 14h | 1.2, 1.4, 3.2, 3.3, 3.4, 3.5 | `feat/m05/3-6/dev1/autopublish` | **Hoàn thành** | Worker tự đăng hằng ngày lên 2 CMS, idempotency key, sửa lỗi Magento E9/E10, EmergencyStopService, lệnh autoseo:emergency-stop và nút dừng khẩn cấp Filament. Commit: `c62dff4` |

---

## MỐC V1.2: AUDIT SEO, KẾ HOẠCH NỘI DUNG & BÀN GIAO V1 (66h - Hạn: 09/12/2026)

| Mã việc | Chủ nhiệm | Reviewer | Giờ dự kiến | Phụ thuộc | Nhánh đề xuất | Trạng thái | Nội dung chính |
| :---: | :---: | :---: | :---: | :---: | :--- | :---: | :--- |
| **1.1** | Dev1 | Dev2 | 12h | 0.4 | `feat/m04/1-1/dev1/crawler` | **Hoàn thành** | Web Crawler & SEO Audit: SitemapCrawlerService, HtmlSeoExtractor, SeoAuditService chống SSRF và lệnh autoseo:audit-website. Commit: `251ada7` |
| **1.3** | Dev2 | Dev1 | 8h | 1.1 | `feat/m04/1-3/dev2/audit-report` | **Hoàn thành** | Báo cáo audit SEO tiếng Việt kèm khuyến nghị tối ưu từ AI (AuditPage, AuditReport, BriefBuilder). Commit: `77fb0a9` |
| **2.1** | Dev2 | Dev1 | 8h | 0.3 | `feat/m04/2-1/dev2/keywords` | **Hoàn thành** | Nhập từ khóa/CSV nội bộ, gom cụm AI (Topic Cluster & Search Intent, KeywordsPage). Commit: `77fb0a9` |
| **2.2** | Dev2 | Dev1 | 10h | 2.1 | `feat/m04/2-2/dev2/content-plan` | **Hoàn thành** | Lập kế hoạch nội dung, lịch biên tập kéo thả tuần (ContentPlanPage, WeeklySchedule). Commit: `77fb0a9` |
| **4.1** | Dev1 | Dev2 | 16h | 3.6, 1.3, 2.2 | `test/m06/4-1/dev1/pilot` | Chưa bắt đầu | Chạy thử thực tế 4 ngày (mỗi người 2h/ngày), xuất bản tối thiểu 10 bài/CMS. |
| **4.2** | Dev1 | Dev2 | 12h | 4.1 | `feat/m06/4-2/dev1/handover` | Chưa bắt đầu | Sửa lỗi pilot, tinh chỉnh prompt, viết hướng dẫn và Marketing nghiệm thu. |

---

## MỐC V2: MỞ RỘNG VẬN HÀNH, GSC & NÂNG CAO (180h - Hạn: 02/01/2027)

| Mã việc | Chủ nhiệm | Reviewer | Giờ dự kiến | Phụ thuộc | Nhánh đề xuất | Trạng thái | Nội dung chính |
| :---: | :---: | :---: | :---: | :---: | :--- | :---: | :--- |
| **5.1** | Dev2 | Dev1 | 18h | 4.2 | `feat/m07/5-1/dev2/gsc` | Chưa bắt đầu | Tích hợp Google Search Console (OAuth read-only, clicks, impressions, CTR). |
| **5.2** | Dev1 | Dev2 | 18h | 4.2 | `feat/m07/5-2/dev1/article-update` | Chưa bắt đầu | Cập nhật bài cũ tụt hạng (versioning, diff, conflict detection, rollback). |
| **5.3** | Dev1 | Dev2 | 12h | 5.2 | `feat/m07/5-3/dev1/internal-links` | Chưa bắt đầu | Tự động đề xuất liên kết nội bộ theo ngữ cảnh, kiểm tra URL và duyệt trước khi chèn. |
| **5.4** | Dev2 | Dev1 | 18h | 5.1 | `feat/m07/5-4/dev2/competitor-gap` | Chưa bắt đầu | Phân tích khoảng trống nội dung đối thủ, kết nối API từ khóa trả phí. |
| **6.1** | Dev2 | Dev1 | 24h | 5.4 | `feat/m07/6-1/dev2/quality-plus` | Chưa bắt đầu | Kiểm tra chất lượng nâng cao (chống trùng lặp internet, kiểm chứng dữ kiện). |
| **6.2** | Dev1 | Dev2 | 12h | 5.3 | `feat/m07/6-2/dev1/english` | Chưa bắt đầu | Mở rộng hỗ trợ nội dung tiếng Anh theo prompt version. |
| **6.3** | Dev1 | Dev2 | 12h | 6.2 | `feat/m08/6-3/dev1/notifications` | Chưa bắt đầu | Thông báo qua Telegram/Email/Zalo khi có lỗi xuất bản hoặc bài cần duyệt. |
| **6.4** | Dev2 | Dev1 | 12h | 6.1 | `feat/m08/6-4/dev2/brands-rbac` | Chưa bắt đầu | Phân quyền phòng ban và quản lý nhiều thương hiệu / website độc lập. |
| **6.5** | Dev1 | Dev2 | 16h | 6.3 | `feat/m08/6-5/dev1/usage-ai-images` | Chưa bắt đầu | Báo cáo chi phí/usage AI, bộ nhớ cache prompt, adapter tạo ảnh AI. |
| **7.1** | Dev1 | Dev2 | 18h | 6.5 | `feat/m08/7-1/dev1/hardening` | Chưa bắt đầu | Giám sát nâng cao, hardening bảo mật, diễn tập phục hồi (restore drill). |
| **7.2** | Dev1 | Dev2 | 20h | 7.1, 6.4 | `test/m08/7-2/dev1/stability` | Chưa bắt đầu | Chạy ổn định 5 ngày làm việc và hoàn tất toàn bộ bàn giao V2. |
