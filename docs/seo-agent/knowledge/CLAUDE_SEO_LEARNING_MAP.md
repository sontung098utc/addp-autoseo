# Bản đồ học từ claude-seo (AG-S10) — 07/11/2026

> **Trạng thái:** tất cả playbook dưới đây là `draft`, mới ở mức **"đã viết"** (bậc 1 trong 4 bậc kiểm chứng của AGENTS.md). Chưa chạy qua bộ mẫu tiếng Việt (AG-K09), chưa tích hợp staging, chưa có người thật nghiệm thu.
> **Nguồn:** `claude-seo` (MIT). Phiên bản đọc: 2.5.0 (riêng `seo-content-brief` 1.0.0). Đã đọc `SKILL.md` của từng skill; **chưa đọc hết thư mục `references/`** (chỉ đọc `seo-drift/references/comparison-rules.md`).
> **Giới hạn:** mốc ngày về Google trong skill gốc (ngừng FAQ rich result 05/2026, danh sách schema bị bỏ, QRG 09/2025) **chưa được xác minh với trang gốc của Google** trong lần này. `RULE_VERIFICATION_2026-11-07.md` (nhánh AG-K01) cũng ghi các mốc 2026 là cần mở trang gốc. Vì vậy playbook chỉ dùng chúng như thông tin, không dùng để chặn bài.

## 1. Bảng skill → vai trò → playbook

| Skill gốc | Vai trò | Playbook (`role/key@1`) | ĐÃ học | BỎ và vì sao | Cần xác minh |
|---|---|---|---|---|---|
| `seo` | Quản lý SEO | `manager/seo-orchestrator` | Quy trình quan sát, phân tích, kiểm chứng, hành động; mỗi khuyến nghị có cơ sở, phụ thuộc, cách kiểm chứng và dấu hiệu thất bại | Subagent song song, điểm sức khỏe 0-100 theo trọng số danh mục, GEO, PDF, chân trang cộng đồng (cần runtime ngoài hoặc không phù hợp) | Mức P0/P1/P2 so với Critical/High/Medium/Low của gốc là ánh xạ do ta đặt |
| `seo-audit`, `seo-page` | Chuyên viên audit | `auditor/audit-onpage` | Nhóm lỗi theo hạng mục, mức nghiêm trọng, cách trình bày bằng chứng; dùng **mã lỗi và điểm theo URL thật của crawler** | Trình duyệt dựng trang, điểm theo danh mục (crawler chỉ có điểm URL `url_average_v1`), CWV, GEO, PDF | Mốc độ dài title/meta (25/70 title, meta theo crawler) là heuristic, Google không có giới hạn cứng (L4a) |
| `seo-technical`, `seo-sitemap`, `seo-schema` | Chuyên viên audit | `auditor/audit-technical` | Hạng mục crawl, lập chỉ mục, sitemap sạch, canonical, dữ liệu có cấu trúc; quy tắc "Chưa đo" thay vì đoán | Quản lý crawler AI, CWV, kết xuất JS, IndexNow, header bảo mật, CVE, trang cho agent (chưa có công cụ đo) | Giới hạn 50.000 URL/50MB của sitemap; danh sách schema đã bỏ và mốc ngày của chúng |
| `seo-cluster` | Nghiên cứu từ khóa | `researcher/keyword-cluster` | 4 loại ý định; ngưỡng trùng top 10 (7-10, 4-6, 2-3, 0-1); trụ cột + vệ tinh; chống ăn mòn từ khóa; liên kết hai chiều; **bản giảm cấp** khi không có SERP | Thu SERP bằng WebSearch/DataForSEO, bản đồ HTML, viết bài qua plugin ngoài, từ khóa mở rộng tiếng Anh | Ngưỡng trùng SERP chưa hiệu chỉnh cho tiếng Việt; chưa có bộ mẫu |
| `seo-sxo`, `seo-competitor-pages`, `seo-content-brief` (phần SERP) | Phân tích đối thủ | `analyst/serp-gap` | Loại trang chủ đạo của SERP và lệch loại trang; 3 kiểu khoảng trống; loại tên miền không phải đối thủ; ưu tiên Tác động x Lợi thế / Công sức; nguyên tắc công bằng khi so sánh; **cổng cứng: không có SERP thật thì không kết luận** | Tìm SERP tự động, điểm SXO 0-100, điểm persona, wireframe, sinh trang so sánh (thang tự đặt, thiếu cơ sở, cần công cụ) | Ngưỡng 60%/40% của "đồng thuận" là quy ước của gốc; chưa có nguồn dữ liệu SERP được duyệt (AG-F04) |
| `seo-plan`, `seo-content-brief` | Chiến lược nội dung | `strategist/content-plan` | Khám phá, kiến trúc, 4 pha; Website Relevance Rule; Site Structure Coverage Rule | Phân tích đối thủ ngoài, ước lượng domain authority, KPI số, mẫu ngành tiếng Anh, xây liên kết ngoài, PR | Mốc tuần các pha chỉ là gợi ý; chưa thử với SME Việt Nam thật |
| `seo-content` (cấu trúc), `seo-content-brief` (đặt từ khóa) | Người viết | `writer/article-draft` | Heading đúng bậc, đoạn ngắn, từ khóa tự nhiên (không mật độ), nguồn và ngày, dấu hiệu nội dung AI kém; **đầu ra JSON, không bịa số liệu, nguồn chỉ từ đầu vào** | Điểm Flesch, số từ theo loại trang như luật, GEO, script làm sạch watermark, schema FAQ để lấy rich result | L1 mật độ 3% không có căn cứ Google; L2 số từ chưa xác minh; L3 FAQ |
| `seo-content` (E-E-A-T, Who/How/Why) | Biên tập | `editor/eeat-check` | Bài kiểm Ai/Thế nào/Vì sao; bốn nhóm tín hiệu E-E-A-T chấm **định tính**; dấu hiệu nội dung kém; Google xét chất lượng chứ không xét có dùng AI | Điểm E-E-A-T 100 và trọng số 20/25/25/30 (skill gốc nói là mô hình nội bộ, Google không công bố), Flesch, chấm khả năng AI trích dẫn | Ý nghĩa bản QRG 09/2025 chưa đọc trang gốc |
| `seo-drift`, `seo-google` | Giám sát | `monitor/drift-baseline` | Mốc chuẩn, so sánh, lịch sử; ba mức CRITICAL/WARNING/INFO; luật chính (canonical, noindex, H1, title, trạng thái, JSON-LD); chỉ số cần Search Console | SQLite cục bộ, CWV/Lighthouse, băm HTML, PageSpeed/CrUX/GA4/Indexing API, sổ cập nhật Google (cần API hoặc runtime ngoài) | **Chưa nối Search Console** nên mọi chỉ số hiệu suất là `Bị chặn tích hợp`; trường noindex phụ thuộc crawler có lưu robots meta hay không (chưa kiểm) |

## 2. Skill KHÔNG học lúc này
`seo-local`, `seo-maps`, `seo-ecommerce`, `seo-backlinks`, `seo-geo`, `seo-agentic`, `seo-hreflang`, `seo-programmatic`, `seo-images`, `seo-image-gen`, `seo-dataforseo`, `seo-flow`.
Lý do: ngoài phạm vi blog/website công ty hoặc cần API trả phí; `seo-flow` dùng CC BY 4.0 nên nếu sau này dùng phải ghi công riêng (xem `NOTICE.md`). `seo-programmatic` còn gần với rủi ro nội dung tạo hàng loạt.

## 3. Quy tắc claude-seo đã KHÔNG đưa vào như sự thật

| Quy tắc gốc | Cách xử lý trong playbook | Căn cứ |
|---|---|---|
| Mật độ từ khóa mục tiêu | Không có; chỉ "tự nhiên, không nhồi" | `RULE_VERIFICATION` L1: không có số % từ Google |
| Số từ tối thiểu theo loại trang (800/1500...) | Không dùng làm luật; ngưỡng của hệ thống chỉ là heuristic chưa hiệu chỉnh | L2 chưa xác minh; skill gốc cũng ghi đó là sàn, không phải yếu tố xếp hạng |
| Title 50-60, meta 130-150 ký tự | Chỉ là gợi ý hiển thị, không chặn | L4a: Google không có giới hạn cứng |
| Flesch Reading Ease | Bỏ | L8: chỉ có nghĩa với tiếng Anh |
| Điểm E-E-A-T có trọng số | Bỏ; thay bằng soát định tính | Skill gốc: "Google publishes no numeric E-E-A-T weights" |
| Mốc ngày FAQ/HowTo/schema bị bỏ | Ghi "chưa xác minh", không dùng để chặn | L3: cần mở trang gốc |
| Điểm SXO, điểm persona | Bỏ | Thang tự đặt, không có cơ sở bên ngoài |
| Ngưỡng cảnh báo 30 / dừng 50 trang địa phương | Không đưa (ngoài phạm vi) | Không xác minh |

## 4. Điều cần làm tiếp (không làm trong AG-S10)
1. Chạy playbook qua bộ mẫu tiếng Việt (AG-K09) trước khi chuyển bất kỳ cái nào sang `active`.
2. Mở trang gốc của Google để chốt các mốc ngày 2026 và cập nhật cột "Cần xác minh".
3. Đọc nốt các `references/` của skill gốc (eeat-framework, quality-gates, page-type-taxonomy...) trước khi nâng phiên bản.
4. Nối Search Console cho `monitor/drift-baseline`; có nguồn dữ liệu SERP được duyệt cho `analyst/serp-gap` và `researcher/keyword-cluster` chế độ SERP.
5. Kiểm xem crawler có lưu robots meta/canonical đủ để `drift-baseline` và `audit-technical` dùng không.
