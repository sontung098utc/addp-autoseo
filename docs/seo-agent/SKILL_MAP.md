# Bản đồ kỹ năng SEO của đội AI

> v1.0 · 12/11/2026 · Tài liệu thiết kế. Nguồn: đọc `claude-seo/skills/*/SKILL.md` (phiên bản 2.5.0, giấy phép MIT), `claude-seo/docs/ARCHITECTURE.md`, cùng `resources/playbooks/*` và `resources/agents/*` của hệ thống.
> Không có số liệu thị trường nào trong tài liệu này; "Ưu tiên VN" là **đánh giá thiết kế** theo loại website điển hình (bán hàng, dịch vụ, tin tức/nội dung tiếng Việt), chưa kiểm với khách thật. Chủ dự án chỉnh lại ở mục 6 của `ROLE_WORKFLOWS.md`.

## 1. Giấy phép và cách ghi công khi tuỳ biến

- `claude-seo/LICENSE`: **MIT**, © 2026 agricidaniel. MIT cho phép dùng, sửa, phân phối với điều kiện giữ thông báo bản quyền và nội dung giấy phép khi sao chép phần đáng kể.
- Hệ thống **không sao chép nguyên văn và không nhúng mã chạy** của claude-seo; mỗi playbook là bản chuyển thể ý tưởng và quy trình bằng lời mới. Cách ghi công đang dùng và tiếp tục dùng:
  1. `resources/playbooks/NOTICE.md`: liệt kê skill gốc, phiên bản, tác giả, playbook dùng.
  2. `lineage` trong từng playbook: `source` (`claude-seo/<skill>`), `source_version` (phải khớp `SKILL.md` gốc, có test `PlaybookSkillsTest` đối chiếu khi có thư mục claude-seo), `license`, `adaptations` (cụ thể đã bỏ/đổi gì).
  3. Vai trò trong `resources/agents/roles/*.php` có `origin` trỏ về skill gốc.
- **Ngoại lệ phải ghi công riêng:** `seo-flow` (khung FLOW, 41 lời nhắc) theo **CC BY 4.0**, © Daniel Agrici. Nếu dùng lời nhắc của nó phải ghi tên tác giả, giấy phép, nguồn `github.com/AgriciDaniel/flow` và nêu chỗ đã sửa. Hiện **chưa dùng**.
- Playbook dựa trên phương pháp nổi tiếng ngoài claude-seo (mục 4) không có "skill gốc" để trỏ `lineage.source`; test hiện bắt `lineage.source` bắt đầu bằng `claude-seo/` chỉ với 7 playbook chính, các playbook khác tự do. Nếu thêm playbook không học từ claude-seo, cần mở rộng cách ghi nguồn (xem SYSTEM_INTEGRATION.md, việc cần code).

## 2. Bảng gán 26 skill của claude-seo

Cột "Ưu tiên VN": **Cao** = nên nối sớm; **TB** = có giá trị, nối sau khi có dữ liệu; **Thấp** = chỉ khi khách cần; **Bỏ** = không dùng, kèm lý do. Cột "Trạng thái" nói về **hệ thống này**, không phải claude-seo.

| Skill gốc | Làm gì (tóm tắt) | Vai trò | Job-type / bước | Dữ liệu / công cụ cần | Ưu tiên VN | Trạng thái trong hệ thống |
|---|---|---|---|---|---|---|
| `seo` | Điều phối, tổng hợp, ngành nghề | manager | mọi job-type (kế hoạch) | báo cáo các vai trò | Cao | Playbook `manager/seo-orchestrator` + `manager/weekly-review` (nháp); chưa có bộ điều phối |
| `seo-audit` | Audit cả site, chấm điểm, ưu tiên | auditor | `site_audit` | crawl | Cao | Mã quét có sẵn (không qua AI); playbook `audit-onpage`, `audit-summary`, `audit-site-rootcause` (nháp) |
| `seo-page` | Phân tích một URL | auditor | `site_audit`, `article_refresh` (chấm bản cũ) | HTML trang | Cao | Playbook `audit-onpage` |
| `seo-technical` | Kỹ thuật: crawl, index, tốc độ, bảo mật | auditor | `site_audit` | crawl, CrUX/PageSpeed (chưa có) | Cao | Playbook `audit-technical`; phần tốc độ thật chưa có nguồn |
| `seo-content` | Chất lượng nội dung, E-E-A-T, dọn dấu vết AI | editor | `article_write.grade`, `article_refresh.grade` | bản nháp, kho kiến thức | Cao | `editor/qa-review` đã nối; `editor/eeat-check` chưa nối; `AiPaddingFilter` có mã riêng |
| `seo-content-brief` | Dàn ý cạnh tranh, số từ theo mục | strategist | `article_write.brief` | SERP (chưa có) | Cao | `brief/content-brief` đã nối; phần SERP chưa |
| `seo-cluster` | Gom cụm, hub-and-spoke, ma trận liên kết | keyword, strategist | `market_research.cluster`, kế hoạch | từ khóa, SERP (chưa có) | Cao | `researcher/keyword-cluster` (chạy bản giảm cấp riêng); thêm `strategist/internal-linking`, `researcher/cannibalization-check` (nháp) |
| `seo-plan` | Kế hoạch, lịch, lộ trình | strategist, manager | `content_plan` | từ khóa, hồ sơ | Cao | `strategist/content-plan` (nháp); `ContentPlanner` chạy bằng luật |
| `seo-sxo` | Lệch loại trang so với SERP | competitor | trước `brief` | SERP thật | Cao | `analyst/serp-gap`; **chưa có SERP** |
| `seo-schema` | JSON-LD: phát hiện, kiểm, tạo | strategist, auditor | `brief`, `write`, `site_audit` | HTML trang | Cao | `strategist/schema-plan`, `auditor/audit-schema` (nháp); chưa nối |
| `seo-sitemap` | Phân tích/tạo sitemap | auditor | `site_audit` | sitemap | TB | Ý đã gộp trong `audit-technical`; `SitemapCrawlerService` có mã đọc sitemap |
| `seo-google` | Search Console, PageSpeed, CrUX, GA4, Indexing | monitor, auditor | `measure`, `article_refresh.trigger` | OAuth Google | **Cao (chặn)** | `monitor/refresh-trigger` (nháp); **chưa có mã Search Console** |
| `seo-drift` | Mốc nền và phát hiện thay đổi | monitor | `measure` | snapshot trang | TB | `monitor/drift-baseline`; chưa nối |
| `seo-dataforseo` | SERP, từ khóa, backlink qua MCP DataForSEO | (nguồn dữ liệu) | cấp dữ liệu cho keyword, competitor, backlinks | tài khoản DataForSEO | TB | Repo chỉ có `DataForSeoKeywordMetricsProvider` (khối lượng từ khóa). Chưa có SERP, backlink. Chưa xác nhận hỗ trợ tiếng Việt/Việt Nam |
| `seo-local` | SEO địa phương: GBP, NAP, đánh giá | keyword, strategist, auditor | brief/kế hoạch | hồ sơ doanh nghiệp, GBP (chưa có) | Cao nếu khách có điểm bán | `researcher/local-keywords` (nháp) |
| `seo-maps` | Lưới địa lý, GBP qua API, bán kính đối thủ | monitor | `measure` (local) | DataForSEO/Geoapify (chưa có) | Thấp | Chưa làm |
| `seo-ecommerce` | Trang sản phẩm, schema Product, Shopping | strategist, auditor | brief/audit | danh mục sản phẩm | Cao nếu có shop | Chưa có playbook; hệ thống đã có bảng sản phẩm cho Trí tuệ (cần kiểm) |
| `seo-backlinks` | Hồ sơ liên kết, khoảng trống | competitor, manager | `site_audit`/kế hoạch quý | dữ liệu backlink (chưa có) | TB | `analyst/backlink-gap` (nháp); người làm PR |
| `seo-competitor-pages` | Trang "X vs Y", "thay thế X" | strategist, writer | kế hoạch | thông tin đối thủ kiểm chứng được | Thấp | Rủi ro pháp lý (so sánh đối thủ); bỏ sinh tự động, chỉ dùng nguyên tắc công bằng (đã ở `serp-gap`) |
| `seo-geo` | Tối ưu cho AI Overviews, ChatGPT, Perplexity | editor, strategist | `grade`, `brief` | HTML, robots | TB | Chưa có playbook; ý "đoạn trả lời rõ, thực thể rõ" áp được ngay bằng prompt biên tập |
| `seo-agentic` | Sẵn sàng cho tác tử AI, llms.txt, WebMCP | auditor | `site_audit` | HTML, robots | Thấp | Còn mới, chưa có căn cứ đủ; để sau |
| `seo-hreflang` | Đa ngôn ngữ/đa vùng | auditor | `site_audit` | HTML | Thấp | Chỉ khi website có nhiều ngôn ngữ; mặc định không cần |
| `seo-images` | Alt, dung lượng, định dạng, lazy load | **designer** (kiểm ảnh vừa tạo), auditor (ảnh cả site), editor | `images` (sau khi tạo), `site_audit` | HTML, ảnh | TB | Playbook `designer/image-qa` (nháp, chưa nạp vào bước); luật `IMG01` (alt) đã có |
| `seo-image-gen` | Tạo ảnh bằng model ảnh | **designer** | `images` (job-type `article_write`) | model ảnh gán cho vai trò (Gemini ảnh hoặc OpenAI-tương thích) | TB | Vai trò `designer` **[NỐI]**; playbook `designer/image-brief` (nháp, chưa nạp vào bước); mã `ArticleImageService` + `GeminiImageGenerator`/`OpenAiCompatImageGenerator` chạy thật |
| `seo-programmatic` | Trang tạo hàng loạt từ dữ liệu | strategist | kế hoạch | nguồn dữ liệu có cấu trúc | Thấp | **Rủi ro cao** (chính sách nội dung tạo hàng loạt). Chỉ dùng phần "cổng chống trang mỏng"; không làm tạo hàng loạt |
| `seo-flow` | Khung FLOW, 41 lời nhắc | manager | kế hoạch | không | Thấp | **CC BY 4.0**, chưa dùng; nếu dùng phải ghi công riêng |

### Agent (19) của claude-seo
Là các tác tử con chạy song song cho từng skill (`seo-technical`, `seo-content`, `seo-schema`, `seo-sitemap`, `seo-performance`, `seo-visual`, `seo-geo`, `seo-agentic`, `seo-local`, `seo-maps`, `seo-backlinks`, `seo-cluster`, `seo-sxo`, `seo-drift`, `seo-ecommerce`, `seo-google`, `seo-dataforseo`, `seo-image-gen`, `seo-flow`). **Không dùng nguyên mẫu "chạy song song nhiều tác tử"**: hệ thống này chạy tuần tự, có ngân sách, nhật ký từng bước và cổng người duyệt. Chỉ lấy phần "một nhiệm vụ, một báo cáo có cấu trúc" làm mô hình vai trò. `seo-performance` và `seo-visual` (ảnh chụp, hiển thị di động) cần trình duyệt và chưa có hạ tầng; `seo-visual` bỏ. `seo-image-gen` (agent ảnh) tương ứng vai trò **Họa viên hình ảnh** (`designer`) của hệ thống này, đã tách khỏi `system` ở giai đoạn 3.

## 3. Cái chưa có mã tương ứng nhưng đã thiết kế

| Nhu cầu | Phụ thuộc | Ghi chú |
|---|---|---|
| Số đo hiệu quả thật | Search Console | Chặn mọi vòng phản hồi |
| Phân tích SERP | Nhà cung cấp SERP | `data-sources.md` mục E chưa tra |
| Backlink | Nhà cung cấp backlink | Chưa có |
| Tốc độ thật (Core Web Vitals) | CrUX / PageSpeed | Miễn phí qua API Google nhưng chưa nối |
| Địa phương | Hồ sơ GBP | Chưa có |

## 4. Phương pháp SEO nổi tiếng được áp dụng (ngoài claude-seo)

Chỉ liệt kê phương pháp **có thật**; "nguồn" là nơi phương pháp được công bố hoặc phổ biến, để người đọc tự kiểm. Mức áp dụng nói trong hệ thống này.

| Phương pháp | Nguồn gốc | Dùng vào đâu trong đội AI | Dữ liệu cần |
|---|---|---|---|
| **E-E-A-T** (kinh nghiệm, chuyên môn, thẩm quyền, tin cậy) và **Search Quality Rater Guidelines** | Tài liệu hướng dẫn của Google cho người đánh giá chất lượng tìm kiếm. E-E-A-T là khung để *người đánh giá* nhận xét, **không phải điểm xếp hạng trực tiếp** | Editor: `editor/eeat-check` (định tính, không cho điểm số). Rủi ro cao (sức khỏe, tài chính, pháp lý) đòi nguồn qua `RiskClassifier` + luật `EVID01`/`KB01` | Kho kiến thức đã duyệt |
| **Nội dung hữu ích, đặt con người lên trước (helpful content / people-first)** | Hướng dẫn của Google Search Central về nội dung hữu ích | Mục tiêu chung của bộ luật chất lượng và rào chắn của writer: không viết để lấp từ khóa, có giá trị riêng | Không |
| **Topical authority / topic cluster (hub-and-spoke, pillar-cluster)** | Mô hình topic cluster do HubSpot phổ biến (khoảng 2017); khái niệm "topical authority" là cách nói của cộng đồng SEO, **không phải chỉ số chính thức của Google** | `strategist/internal-linking`, cụm trong `keyword_clusters`; lập kế hoạch theo độ phủ cụm (`ContentPlanner` đã có điểm độ phủ cụm) | Bảng cụm, bài |
| **Ý định tìm kiếm** (thông tin, điều hướng, giao dịch) | Phân loại của Andrei Broder, 2002 ("A taxonomy of web search") | `keyword` gán ý định; `competitor` so loại trang; editor trả dàn ý lệch ý định | SERP (nếu có) |
| **SXO và phân tích tính năng SERP** | `seo-sxo` của claude-seo; ý "khớp loại trang với SERP" phổ biến trong cộng đồng | `analyst/serp-gap` | SERP thật |
| **Khoảng trống nội dung (content gap)** | Thực hành phổ biến của cộng đồng | `competitor` + bảng `keyword_gaps` (đã có nguồn csv, gsc, crawl, tay) | SERP/GSC |
| **Skyscraper / "10x content"** | Brian Dean (Backlinko) cho Skyscraper, 2013; "10x content" do Rand Fishkin (Moz) nêu | **Chỉ lấy điều chỉnh:** hơn đối thủ ở *giá trị* chứ không ở *độ dài*; không sao chép cấu trúc đối thủ (đã là rào chắn trong `serp-gap`). Phần "liên hệ xin liên kết" do người làm | SERP |
| **Content decay và làm lại bài theo Search Console** | Thực hành phổ biến; không có tiêu chuẩn thống nhất cho ngưỡng | `monitor/refresh-trigger` → `article_refresh`. Mọi ngưỡng là **tham số cài đặt chưa hiệu chỉnh** | Search Console |
| **Entity / semantic SEO** | Google Knowledge Graph và Schema.org (tài liệu công khai) | Schema `Organization`/`Article`, tên thương hiệu nhất quán, hồ sơ doanh nghiệp (`brand_profiles`) | Hồ sơ doanh nghiệp |
| **Liên kết nội bộ theo cụm** | Thực hành phổ biến + `seo-cluster` | `strategist/internal-linking`; luật `LINK01` đã có (chỉ trỏ trang thuộc website) | Bài, cụm |
| **Digital PR / link earning** | Thực hành phổ biến | `analyst/backlink-gap` chỉ *gợi ý mục tiêu*; người thực hiện | Dữ liệu backlink |
| **GEO / AEO (tối ưu cho công cụ trả lời sinh ra)** | Bài nghiên cứu "GEO: Generative Engine Optimization" (Aggarwal và cộng sự, 2023; công bố tại KDD 2024); AEO là cách gọi của cộng đồng | Ở mức biên tập: đoạn trả lời ngắn rõ ràng, thực thể rõ, nguồn trích dẫn; **chưa có phép đo trích dẫn LLM nào đáng tin** nên không hứa kết quả | Không (đo lường chưa có) |
| **Core Web Vitals** (LCP, INP, CLS) | Google web.dev; INP thay FID từ 3/2024 | `auditor` + `seo-google` (CrUX/PageSpeed) | CrUX/PageSpeed |
| **Programmatic SEO có kiểm soát** | Thực hành phổ biến; chính sách spam của Google về "nội dung tạo hàng loạt" (scaled content abuse, công bố 3/2024) | Chỉ làm khi có dữ liệu thật mỗi trang; mặc định **không tạo hàng loạt**; cổng chống trang mỏng | Dữ liệu có cấu trúc |
| **SEO local và thương mại điện tử** | Hướng dẫn Google Business Profile, hướng dẫn dữ liệu sản phẩm của Google | `researcher/local-keywords`, schema `Product`/`LocalBusiness` | GBP, danh mục |
| **OKR / KPI cho SEO** | OKR: Andy Grove (Intel), phổ biến bởi John Doerr | `manager/weekly-review`: **KPI do chủ dự án đặt**, đội AI không tự đặt | Search Console |

Điều đã **cố ý không đưa** vào: mật độ từ khóa theo phần trăm, "điểm Flesch" cho tiếng Việt, điểm "sức khỏe SEO" tổng hợp 0-100, dự báo thứ hạng/lưu lượng. Các test (`PlaybookSkillsTest`) đang chặn các thứ này.

## 5. Thứ tự nối đề xuất (theo giá trị / độ khó)

1. Giữ cái đã nối (strategist, writer, editor) và làm tốt: nối `editor/eeat-check`, phản biện dàn ý, trả bài có lý do.
2. Kỹ năng **không cần dữ liệu ngoài**: `internal-linking`, `schema-plan`, `audit-schema`, `audit-site-rootcause`, `cannibalization-check`, `weekly-review`.
3. Kỹ năng **cần Search Console** (một nguồn mở khóa nhiều thứ): `refresh-trigger`, đo lường, KPI.
4. Kỹ năng **cần SERP / backlink / GBP**: `serp-gap`, `backlink-gap`, `local-keywords` đầy đủ, theo ngân sách chủ dự án.
