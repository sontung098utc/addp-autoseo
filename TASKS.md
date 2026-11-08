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

---

## MỐC M09: ĐỘI AI SEO TỰ ĐỘNG — ÁP DỤNG ĐỊNH HƯỚNG (ĐỀ XUẤT — ngoài WBS gốc, CHƯA estimate chính thức)

> Đề xuất từ Dev2, **chờ Dev1 duyệt estimate/phạm vi**. Không cộng vào 432h gốc; không đổi trạng thái task gốc. Chi tiết: `docs/seo-agent/APPLY_PLAN_V1.md` (cổng G0–G3). Quy ước nhánh: `<loại>/m09/<mã>/<dev>/<mô-tả>`.
> Giờ là ước lượng thô của người đề xuất. Mọi trạng thái giữ "Chưa bắt đầu" cho tới khi có commit + bằng chứng.

| Mã việc | Chủ nhiệm | Reviewer | Giờ (thô) | Phụ thuộc | Nhánh đề xuất | Trạng thái | Nội dung chính |
| :---: | :---: | :---: | :---: | :---: | :--- | :---: | :--- |
| **AG-H01** | Dev2 | Dev1 | 3h | - | `docs/m09/ag-h01/dev2/m09-docs` | Chưa bắt đầu | Đưa tài liệu M09 vào repo + link README + mục M09 (PR này) |
| **AG-H02** | Dev2 | Dev1 | 1h | - | (cục bộ) | Chưa bắt đầu | Dựng lại image Docker (WebP) + chạy toàn bộ test + báo cáo baseline |
| **AG-H03** | Dev1 | Dev2 | 2h | - | `chore/m09/ag-h03/dev1/ci-verify` | Chưa bắt đầu | Xác nhận/bật GitHub Actions CI; một lượt xanh trên `develop` |
| **AG-H04** | Dev1 | Dev2 | 10h | - | `docs/m09/ag-h04/dev1/post-merge-review` | Chưa bắt đầu | Hậu kiểm review chéo khối UI Dev2 đã merge trực tiếp (bảo mật + hành vi) |
| **AG-H05** | Dev2 | Dev1 | 6h | - | `fix/m09/ag-h05/dev2/ux-debt` | Chưa bắt đầu | Tiêu đề trang khớp menu; widget Tổng quan không tải lười/không vẽ khung rỗng |
| **AG-H06** | Dev2 | Dev1 | 2h | H05 | (không PR) | Chưa bắt đầu | Dọn nhánh/worktree đã merge (sau xác nhận) |
| **AG-H07** | Dev2 | Dev1 | 0,5h | - | (không PR) | Chưa bắt đầu | Cập nhật issue #6, theo dõi #9 |
| **AG-H09** | Dev2 | Dev1 | 2h | H01 | `docs/m09/ag-h09/dev2/task-evidence` | Chưa bắt đầu | Ghi mức bằng chứng 4 tầng cho các task đã "Hoàn thành" |
| **AG-F04** | Dev2 | Dev1 | 6h | - | `docs/m09/ag-f04/dev2/seo-data-source` | Chưa bắt đầu | Chọn nguồn dữ liệu SEO cho Việt Nam (GSC + từ khóa/SERP), chi phí, quyền riêng tư |
| **AG-K01** | Dev2 | Dev1 | 6h | - | `docs/m09/ag-k01/dev2/knowledge-catalog` | Chưa bắt đầu | Danh mục học từ claude-seo + xác minh quy tắc SEO với tài liệu Google |
| **AG-F05** | Dev2 | Dev1 | 6h | - | `docs/m09/ag-f05/dev2/usability-results` | Chưa bắt đầu | Thử nghiệm khả dụng 5 người trên mẫu giao diện |
| **AG-B00** | Dev1 | Dev2 | 4h | - | `feat/m09/ag-b00/dev1/audit-job` | Chưa bắt đầu | Job nền quét audit (bọc `SeoAuditService`) — issue #9 |
| **AG-B08** | Dev1 | Dev2 | 4h | - | `fix/m09/ag-b08/dev1/stop-persist` | Chưa bắt đầu | Bền hóa trạng thái dừng khẩn cấp (đang lưu trong Cache) — issue #9 |
| **AG-F03** | Dev1 | Dev2 | 8h | - | `docs/m09/ag-f03/dev1/adr-automation` | Chưa bắt đầu | ADR: orchestrator, vai trò n8n, giấy phép |
| **AG-U00** | Dev2 | Dev1 | 5h | H05, G0 | `feat/m09/ag-u00/dev2/nav-advanced` | Chưa bắt đầu | Gọn menu: nhóm "Nâng cao" theo vai trò, giữ nguyên URL |
| **AG-U01** | Dev2 | Dev1 | 6h | G0 | `feat/m09/ag-u01/dev2/status-chips` | Chưa bắt đầu | Nhãn trạng thái + chip AI/Nhập/Mẫu |
| **AG-U11a** | Dev2 | Dev1 | 3h | G0, U00 | `feat/m09/ag-u11a/dev2/stop-banner` | Chưa bắt đầu | Banner dừng xuất bản toàn cục (chỉ đọc) |
| **AG-U02** | Dev2 | Dev1 | 8h | U01 | `feat/m09/ag-u02/dev2/how-it-works` | Chưa bắt đầu | Trang "Cách hoạt động" + FeatureRegistry |
| **AG-U23** | Dev2 | Dev1 | 10h | U01 | `feat/m09/ag-u23/dev2/why-not-published` | Chưa bắt đầu | "Vì sao chưa đăng?" bằng luật thật (mở rộng `ScheduleExplainer`) |
| **AG-U19** | Dev2 | Dev1 | 12h | U01, U23 | `feat/m09/ag-u19/dev2/daily-digest` | Chưa bắt đầu | Bản tin hằng ngày từ dữ liệu thật |
| **AG-K04** | Dev2 | Dev1 | 6h | - | `feat/m09/ag-k04/dev2/audit-mapping` | Chưa bắt đầu | Ánh xạ mã lỗi crawler ↔ P0/P1/P2 + `score_method` |
| **AG-U05** | Dev2 | Dev1 | 12h | K04, B00 | `feat/m09/ag-u05/dev2/audit-db-source` | Chưa bắt đầu | Audit đọc dữ liệu crawler thật (`seo_audits`, `crawled_urls`) |
| **AG-K03** | Dev2 | Dev1 | 12h | K01 | `feat/m09/ag-k03/dev2/rubric-metadata` | Chưa bắt đầu | Rubric có nguồn/lý do/ngày rà soát; giữ mã luật |
| **AG-U26** | Dev2 | Dev1 | 6h | U00 | `feat/m09/ag-u26/dev2/role-matrix` | Chưa bắt đầu | Ma trận quyền theo vai trò cho hành động mới |
| **AG-K02…K13, U09/U10/U15/U22/U24/U25, A01/A03/A05a** | Dev2 | Dev1 | theo kế hoạch | G2 (Dev1) | `feat/m09/ag-<mã>/dev2/<mô-tả>` | Chưa bắt đầu | Giai đoạn 2–3: Playbook, rubric, vòng tự sửa, ba chế độ, đường mỏng WordPress (chỉ đẩy nháp) — xem `APPLY_PLAN_V1.md` §6 |
| **AG-B01…B12, A02** | Dev1 | Dev2 | theo kế hoạch | G0 | `feat/m09/ag-<mã>/dev1/<mô-tả>` | Chưa bắt đầu | Giai đoạn 2: migration `agent_*`, router đa model (bỏ Mock im lặng), ngân sách, orchestrator, thông báo |
