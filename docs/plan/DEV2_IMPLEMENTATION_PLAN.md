# KẾ HOẠCH TRIỂN KHAI VAI TRÒ DEV 2 — addp-autoseo

> Phạm vi: **Dev2** (Filament UI · WordPress Connector · AI/Security-input · SEO audit/planning · QA).
> Tham chiếu gốc: `Kế hoạch phần mềm Auto SEO.xlsx` (đã đọc cả công thức lịch), `docs/plan/sheets_tsv/*`, `TASKS.md`, `AGENTS.md`, `contracts/*`, và toàn bộ source `app/`, `database/`, `tests/`, `routes/`, `.github/`.
> Ngày lập: **05/11/2026** · Cập nhật **05/11/2026 10:25** sau khi fetch `origin` (local `main` đang chậm 3 commit — xem §1.0).

---

## 0. Trả lời trực tiếp: Dev2 làm gì, Dev1 làm gì

### 0.1 Tổng lượng việc

| | Giờ dự kiến | Mốc | Lịch theo sheet 2.2 | Lịch điều chỉnh (đề xuất) |
|---|---|---|---|---|
| **Dev2** | **188h** (43,5% của 432h) | 0.3, 0.5, 1.2, 3.4, 3.5, 2.1, 2.2, 1.3, 5.1, 5.4, 6.1, 6.4 + ½ 0.1/0.2/0.4/3.1/3.6/4.1/4.2/7.2 | 03/11 → **03/01/2027** | **06/11/2026 → 25/12/2026** |
| **Dev1** | **244h** | 20 mã việc còn lại + review toàn bộ 12 task của Dev2 | 03/11 → 02/01/2027 | V2 chốt 30/12/2026 |

### 0.2 Dev2 phụ trách 12 mã việc (theo AGENTS.md §2 + sheet 2.1 cột W/X)

| Mã | Nội dung chính | Giờ | Mốc | Hạn gốc | Hạn điều chỉnh | Phụ thuộc | Nhánh |
|:--:|---|:--:|:--:|:--:|:--:|:--|:--|
| **0.3** | AI PoC tiếng Việt: prompt version, đo token/chi phí/bài | 10 | V1.0 | 05/11 | **09/11** (đang trễ — ưu tiên #1) | 0.1 ✅ | `feat/m01/0-3/dev2/ai-poc` |
| **0.5** | Filament panel tiếng Việt + chuẩn dữ liệu website | 10 | V1.0 | 10/11 | **11/11** | 0.1 ✅ | `feat/m01/0-5/dev2/filament` |
| **3.4** | Màn duyệt bài + cấu hình số bài/số ảnh/lịch | 10 | V1.0 | 11/11 | **13/11** | 0.5, 0.1 | `feat/m03/3-4/dev2/review-ui` |
| **1.2** | WordPress Connector (REST v2 + App Password) | 16 | V1.0 | 14/11 | **18/11** | 0.4 (Dev1), 0.5 | `feat/m02/1-2/dev2/wordpress` |
| **3.5** | QA nội dung cơ bản (chặn bài lỗi) | 8 | V1.0 | 19/11 | **20/11** | 3.1 (Dev1), 3.4 | `feat/m03/3-5/dev2/quality` |
| **2.1** | Nhập từ khóa/CSV + AI gom cụm | 8 | V1.2 | 21/11 | **23/11** | 0.3 | `feat/m04/2-1/dev2/keywords` |
| **2.2** | Kế hoạch nội dung, topic cluster, lịch biên tập | 10 | V1.2 | 23/11 | **24/11** | 2.1 | `feat/m04/2-2/dev2/content-plan` |
| **1.3** | Báo cáo audit SEO tiếng Việt + đề xuất AI | 8 | V1.2 | 03/12 | **27/11** | 1.1 (Dev1) | `feat/m04/1-3/dev2/audit-report` |
| **5.1** | Google Search Console (OAuth read-only) | 18 | V2 | 12/12 | **03/12** | 4.2 | `feat/m07/5-1/dev2/gsc` |
| **5.4** | Đối thủ + khoảng trống nội dung + API từ khóa trả phí | 18 | V2 | 16/12 | **13/12** | 5.1 | `feat/m07/5-4/dev2/competitor-gap` |
| **6.1** | QA nâng cao: trùng lặp internet, kiểm dữ kiện, giọng thương hiệu | 24 | V2 | 21/12 | **17/12** | 5.4 | `feat/m07/6-1/dev2/quality-plus` |
| **6.4** | Phòng ban + đa thương hiệu + RBAC từng website | 12 | V2 | 23/12 | **21/12** | 6.1 | `feat/m08/6-4/dev2/brands-rbac` |

**Cộng tác (có giờ trong WBS):** 0.1 (4h), 0.2 (2h), 0.4 (2h), 3.1 (2h), 3.6 (2h), 4.1 (8h pilot), 4.2 (6h), 7.2 (10h quan sát).

**Nghĩa vụ reviewer (không có giờ riêng):** review 12 PR của Dev1 trước mỗi mốc, mỗi PR ≤ 1 giờ, họp 10 phút đầu ngày, cập nhật `TASKS.md` + sheet 7 sau mỗi task.

### 0.3 Dev1 làm gì (tóm tắt để biết phần phụ thuộc)

- **0.1** Chốt phạm vi & contracts — *đã xong* (`5f08e6e`).
- **0.2** Hạ tầng PHP 8.2 / MariaDB 10.6 / Redis 7.2 / Docker 6 service / CI / backup-restore. **Còn nợ:** HTTPS + staging riêng cho Dev2 (trước 12/11).
- **0.4** Auth, **Policies 3 vai trò**, quản lý website, **mã hóa credential + che log**. Phần panel đã có sẵn → Dev2 xây tiếp trên đó.
- **3.1** Pipeline AI dàn ý → bài → metadata + sanitizer. **Hiện tại không chạy đúng** (xem §1.2) — Dev1 phải sửa trước 3.5.
- **1.4** ✅ **Đã xong** (`d5e5e4f`, merge `abae1f4`): `app/Integrations/Magento/MagentoBridgeClient.php` + `OAuth/OAuth1Signer.php` + package `packages/magento-company-blog-api/` (ACL/webapi/DI) + 2 test. Dev2 **kế thừa mẫu này** cho connector WordPress — xem §3.1 điểm dùng lại và §1.2 mã E9–E12 (chỗ WordPress phải làm khác, không copy sai).
- **3.2** Redis Queue + Scheduler + retry/backoff + khóa theo site; **3.3** thư viện ảnh (nén/alt/featured); **3.6** worker tự đăng 2 CMS + `publication_key` + nút dừng khẩn cấp.
- **1.1** Crawler sitemap/on-page + chống SSRF; **5.2** cập nhật bài cũ (version/diff/rollback); **5.3** internal link.
- **6.2** Tiếng Anh, **6.3** Thông báo, **6.5** Usage/cache/ảnh AI, **7.1** Hardening, **4.1/4.2/7.2**.
- **Dev1 phải giao cho Dev2 (mốc chặn):** `PublisherInterface` FINAL + hợp đồng lỗi thống nhất (ERR01, `needs_reconcile`, `publication_key`) — **12/11**; pipeline JSON schema chuẩn — **16/11**; `GET /storage` HTTPS staging WP — **12/11**.

---

## 1. Trạng thái source so với kế hoạch (đọc thực tế, không báo cáo)

### 1.0 Trạng thái git tại thời điểm lập kế hoạch (đã `git fetch --all`)

| Ref | SHA | Ghi chú |
|---|---|---|
| `origin/main` = `origin/develop` | `0b87483` | Mới nhất — gồm commit `abae1f4` merge Magento + `0b87483` cập nhật TASKS.md |
| `origin/feat/m02/1-4/dev1/magento-bridge` | `d5e5e4f` | Đã merge; chỉ còn 1 diff TASKS.md so với main |
| `origin/feat/m03/3-1/dev1/content-pipeline` | `402a440` | **Stale** — nội dung đã có trên main, không còn commit mới |
| `main` (local) | `deb3a1a` | ⚠️ **Chậm 3 commit** so với origin |

> Trước khi tạo bất kỳ nhánh task nào: `git checkout develop && git reset --hard origin/develop` (hoặc `git pull --ff-only`), rồi `git checkout -b feat/m01/0-3/dev2/ai-poc`. Không phát triển trên `main` local cũ.

### 1.1 Cái đã có thật

`composer.json` (Laravel 11.9, Filament 3.2, PHP 8.2) · migrations `websites` / `articles`+`article_versions` / `publications` · 5 model + casts + relations · 3 Filament Resource (vi/Filament 3, tự viết label tiếng Việt) · `AdminPanelProvider` (`->login()`, **vẫn chưa có** `->authorize`/policies) · `ContentPipelineService` + `HtmlSanitizer` + `GeminiAiProvider` + `MockAiProvider` · `PublisherInterface`/`AiProviderInterface` · 5 contract JSON · 5 TSV plan · CI `ci.yml` · Docker 6 service · 4 file test · **`MagentoBridgeClient` + `OAuth1Signer` + package `magento-company-blog-api` (1.4, `d5e5e4f`)** · 2 test Magento mới.

### 1.2 Lệch kế hoạch / lỗi phải xử lý trong giai đoạn 1 (xem §4)

| # | Vấn đề | Ảnh hưởng |
|---|---|---|
| E1 | Sheet 7 + `TASKS.md` đánh dấu **0.2/0.4/3.1 = Hoàn thành**, nhưng **không có `app/Policies/`**, không có `authorize()`, không có rate limit login, không có secret che log (vi phạm AC08/SEC02/SEC03, F-01 "Chưa nghiệm thu") | Chặn nghiệm thu V1.0; Dev2 không được xây màn duyệt trên nền chưa có quyền |
| E2 | `ContentPipelineService` dùng `env('GOOGLE_AI_API_KEY')` trực tiếp; `GeminiAiProvider` coi `ai_provider = model`; **fallback im lặng sang Mock** khi JSON lỗi → bài giả báo "thành công"; không capture `prompt_version` của từng bước | Vi phạm *Honest Evidence*; 3.5/1.3/2.1 sẽ dựa trên dữ liệu không đáng tin |
| E3 | `HtmlSanitizer` dùng `strip_tags` + regex, **không cấm trường `style`/`id`/`target`/`rel`**, vẫn cho `src` với URL tùy ý (SSRF phía trình duyệt/CMS) | SEC05 chưa đạt; test hiện tại không phủ `<p style=…>` |
| E4 | `routes/console.php` schedule `autoseo:publish-due-posts` nhưng **lệnh chưa tồn tại** (`app/Console` trống) → mỗi phút ghi lỗi | Scheduler đỏ, dễ bỏ qua lỗi thật khi 3.2 lên |
| E5 | `WorkflowEnum.json` thiếu `scheduled/published` cho article (đã có trong migration); tên khoá `draft` vs `outline` trong contract không khớp DB (`article_versions.status` vs `review_status`) | Contract ≠ DB → 3.4/3.5 phải chốt lại (PR contract riêng, Dev1 review) |
| E6 | `DatabaseSeeder` tạo 3 user với **mật khẩu mặc định** (`admin123456`…) và credential WP giả | SEC02: phải là placeholder "chỉ local", chặn chạy ở `APP_ENV != local` |
| E7 | `WebsiteResource` cho sửa `encrypted_credentials` bằng KeyValue thô, **không che giá trị**, cho người không phải admin | AC08 |
| E8 | `Publication` chưa có `publication_key` unique **theo (website_id, key)** — hiện unique toàn cục (đúng về an toàn, cần xác nhận lại), chưa có index quota `(website_id, published_at)` | AC03/AC05 |

**Mới phát hiện sau khi đọc 1.4 Magento (`d5e5e4f`):**

| # | Vấn đề | Vì sao liên quan tới Dev2 |
|---|---|---|
| E9 | `MagentoBridgeClient::createDraft()` **tự sinh `publication_key` riêng** (`magento-{id}-art{a}-v{v}`) thay vì dùng key do publisher/Publication truyền vào; `publish()` lại gọi `getByPublicationKey` với `$publication->publication_key` của Publication — **hai key khác nhau** ⇒ đối soát timeout sẽ **không tìm ra** bài đã tạo ⇒ nguy cơ đăng trùng (AC04/F-14) | Đây chính là điều `PublisherInterface` FINAL (Q1) phải chốt: **key do hệ thống cấp, connector không tự sinh**. WordPress connector của Dev2 làm theo hợp đồng mới, **không copy cách này** |
| E10 | `MagentoBridgeClient::publish()` khi `cms_post_id` rỗng sẽ **tự `createDraft()` rồi publish ngay** — gọi publish 2 lần có thể tạo 2 bài nếu bước 1 timeout | Dev2 kiểm ca này trong QA07 và không nghiệm thu 3.6 khi chưa xử lý |
| E11 | Lỗi mạng/timeout ném exception thô (`ConnectException`, `ServerException`) — **không map thành mã ERR01…ERR07**, không có `needs_reconcile` | Dev2 định nghĩa `ErrorMapper` phía nào cũng dùng; bộ mã lỗi cần đưa vào `app/Contracts` để cả WP và Magento cùng implement (Dev1 mở PR) |
| E12 | Test của Magento là **mock 100%** (`MockHandler`), chỉ phủ happy path + 404; **chưa có phủ 401/403/429/5xx/timeout**; không có test thu hồi credential (AC06) | Nghiệm thu 1.4 ở mức "test giả lập", **chưa lên mức tích hợp staging**. Dev2 (reviewer của 1.4) phải ghi rõ trong PR review, và bộ test WordPress của Dev2 phải phủ đủ 7 mã lỗi |

> **Quyết định trình bày với Dev1:** dùng **1 Issue "Conformance 0.2/0.4/3.1 + 1.4"** (Dev1 chủ, Dev2 review) để hạ trạng thái về *Chờ review* thay vì tiếp tục ghi nhận Hoàn thành. Không tự ý sửa file Dev1.

---

## 2. Nguyên tắc làm việc (buộc tuân thủ)

1. **Mỗi ngày 6h** (2h buổi sáng + 2h chiều + 2h tối/linh hoạt), Thứ Hai–Thứ Bảy, Tết/1-1/30-4 là ngày nghỉ theo sheet 1.1.
2. **Một task = một nhánh** từ `develop` (nhánh mới nhất, đã rebase), đúng cú pháp `<loại>/<Mxx>/<mã>/dev2/<slug>`; **không** commit thẳng `develop/main`; **squash merge** sau khi Dev1 review + CI xanh.
3. **Mọi task làm theo 4 mức bằng chứng:** đã code → mock test → tích hợp staging → người thật nghiệm thu. Không đủ môi trường thì ghi **"Chưa chạy / Bị chặn tích hợp"**, tuyệt đối không ghi 0/mock thành công.
4. **Báo cáo cuối ngày** vào `reports/daily/YYYY-MM-DD-dev2.md` (dùng `reports/DAILY_REPORT_TEMPLATE.md`) + cập nhật đúng dòng trong sheet 7 và `TASKS.md` với commit SHA + URL PR.
5. **Allowlist:** chỉ sửa `app/Filament/**`, `app/Integrations/WordPress/**`, `app/Services/Ai/**`, `app/Services/Content/**`, `app/Services/Seo/**`, `resources/prompts/**`, `app/Http/**` (dev2-only), `tests/**` của task đó, `docs/**`. **Không chạm** `app/Models`, `database/migrations`, `contracts/`, `config/`, `docker*/`, `.github/`, `routes/console.php`, `app/Jobs/**`, `app/Console/**` — nếu cần đổi thì mở Issue cho Dev1 và chờ PR độc lập.
6. **Secret:** không đưa App Password/API key vào code, log, test fixture hay PR. Test dùng `GuzzleHttp\Handler\MockHandler` (đúng pattern `MagentoBridgeClientTest`); staging dùng credential thu hồi được.

---

## 3. Kiến trúc đích Dev2 sẽ dựng

```
app/
  Integrations/WordPress/
    WordPressClient.php        # HTTP layer: Basic Auth, UA, timeout, retry chuẩn, log redaction
    WordPressPublisher.php     # implements PublisherInterface (final interface từ Dev1)
    Media/WordPressMediaService.php
    Taxonomy/WordPressTaxonomyService.php
    Seo/SeoMetaAdapter.php     # interface + RankMathAdapter | YoastAdapter | NullAdapter
    Support/{SafeUrl, ErrorMapper, ReadbackVerifier}.php
    Bridge/                    # mu-plugin nhỏ (chỉ khi Không ghi được meta qua REST)
  Services/Seo/
    RuleEngine.php, Rules/*                                      # 18 chỉ số → P0/P1/P2
    AuditReportBuilder.php, AiSuggestionsService.php             # 1.3
    Keyword/KeywordImporter.php, KeywordClusterer.php            # 2.1
    ContentPlan/ContentPlanBuilder.php                           # 2.2
    Quality/ContentQualityChecker.php                            # 3.5
    QualityPlus/*.php                                            # 6.1
    Gsc/GscClient.php, GscReportBuilder.php                      # 5.1
    Competitor/CompetitorAnalyzer.php, KeywordSource/*.php       # 5.4
  Filament/
    Resources/  Website, Article, ArticleVersion, Publication, Keyword, Campaign/ContentPlan,
                SeoAuditReport, GscQueryReport, Brand, Department, User
    Pages/      CampaignSettings, SafetyStop, AiUsageReport
    Widgets/    QueueHealth, UpcomingPublications, CostPerArticle
    Concerns/   ScopedToWebsite, HasWorkflowActions
resources/prompts/
  v1/{outline,article,audit_suggestion,keyword_cluster,competitor_gap,quality_plus}.md
```

**Nguyên tắc UI:** mọi hành động được kiểm quyền **ở server** bằng policy Dev1 cung cấp (không ẩn nút để "phân quyền"); hiển thị cờ `capability_flags` của website để không bật nhầm tính năng; hiển thị giờ theo `Asia/Ho_Chi_Minh`, lưu `scheduled_at_utc`; mọi lỗi hiện dạng tiếng Việt có mã lỗi (ERR01…) + nút "Xem chi tiết" (không chứa credential).

### 3.1 Kế thừa từ connector Magento (1.4 — `d5e5e4f`)

| Dùng lại được | Nội dung | Ghi chú |
|---|---|---|
| Cấu trúc class | `Implements PublisherInterface`, inject `GuzzleHttp\Client` qua constructor (testable bằng `MockHandler`) | Dev2 theo **đúng pattern này** cho `WordPressClient` |
| Test pattern | `new Client(['handler' => HandlerStack::create($mock)])` + tạo `Website/Article/ArticleVersion/Publication` bằng model | Dev2 mở rộng thành **7 ca lỗi** (E12) |
| Credential | Đọc từ `$website->encrypted_credentials` (cast `encrypted:array`) — **không** đọc từ env | Giữ nguyên |
| Tra cứu key | `getByPublicationKey` trả `null` khi 404, ném lỗi khi 4xx/5xx khác | WordPress dùng `meta_key` + `search` hoặc REST field |
| Bridge server-side | `packages/magento-company-blog-api/` có `webapi.xml` + `acl.xml` + service contract | Nếu phải làm mu-plugin WP, dùng **cùng mức tối thiểu** (chỉ endpoint cần, ACL rõ) |

| **KHÔNG** copy (xem E9–E11) | Làm đúng thay thế |
|---|---|
| Tự sinh `publication_key` trong connector | Nhận `$publication->publication_key` từ publisher (Dev1) |
| `publish()` tự tạo draft khi thiếu `cms_post_id` | Chỉ dùng `remote_id` đã lưu, hoặc tra key; không có thì trả lỗi để publication → `needs_reconcile` |
| Ném exception thô (`ClientException`, `ConnectException`) | `ErrorMapper` → `CmsError(code)` chuẩn ERR01–ERR07 |

---

## 4. KẾ HOẠCH CHI TIẾT THEO TASK

### GIAI ĐOẠN 0 — Ổn định nền tảng trước khi xây (06/11 → 11/11, 22h)

#### S0.0 — Kickoff & chốt phương án (Thứ Sáu 06/11, 2h)

- Họp 60 phút với Dev1: chốt **Issue Conformance 0.2/0.4/3.1 + 1.4**; xác nhận interface `PublisherInterface` FINAL và **bộ mã lỗi** (ERR01 timeout-sau-khi-tạo, ERR02 401/403, ERR03 404, ERR04 429 throttle, ERR05 5xx, ERR06 payload không hợp lệ, ERR07 mất kết nối) với ánh xạ `needs_reconcile` ↔ ERR01/ERR06.
- **Chốt 3 điểm do 1.4 mới lộ ra:** (a) `publication_key` **do hệ thống cấp và truyền xuống connector** — Magento hiện tự sinh, phải sửa; (b) `publish()` **không được tự tạo draft** khi thiếu `remote_id`; (c) `CmsError` + `ErrorMapper` đặt trong `app/Contracts` để WP và Magento dùng chung (Dev1 mở PR).
- Review ngược 1.4 (`d5e5e4f`): vì chỉ có mock test happy-path, Dev2 ký review ở mức **"Đã code + test giả lập"**, **không** xác nhận mức "Tích hợp staging" (E12).
- Xác nhận **dữ liệu thật cần xin**: staging WP URL + 1 user chuyên dụng API (Application Password), plugin SEO đã cài (Rank Math **hoặc** Yoast), GSC property, Google Cloud project cho OAuth read-only, key Gemini.
- Kết quả: Issue cho mỗi task trong sprint + `docs/plan/DEV2_PENDING_INPUTS.md` (danh sách chờ cấp quyền, có hạn).
- **Blocker nếu không có staging WP trước 12/11:** 1.2 chỉ giao mức *mock + contract test*, báo cáo rõ "Bị chặn tích hợp".

#### S0.1 — 0.3 AI PoC (07/11 → 09/11; 10h)

Mục tiêu: có chứng cứ đo chất lượng + chi phí trước khi Task 3.5/2.1 dựa vào AI.

- `resources/prompts/v1/{outline,article}.md` + `PromptRegistry` (đọc file, tính hash → `prompt_version`).
- `AiProviderInterface` giữ nguyên; thêm `App\Services\Ai\AiProviderFactory` (config-driven, `config/services.php` **do Dev1 thêm**); bỏ `env()` trực tiếp.
- `MockAiProvider` **không bao giờ** tự chạy nếu `AI_DEFAULT_PROVIDER != mock`; khi Gemini trả JSON sai → throw `AiOutputInvalidException`, **không fallback im lặng**.
- Bộ 20 từ khóa tiếng Việt (3 ngành, 3 mức cạnh tranh) trong `tests/Fixtures/ai/poc_keywords.json`; script đo `docs/evidence/0-3/` ghi **model, prompt_version, tokens in/out, cost VND thật, thời gian, trung bình/độ lệch**.
- So sánh ≥2 model nếu có ngân sách; chốt model mặc định + ngưỡng chi phí/bài **≤3.000 VND** (sheet 5_B).
- **AC:** chạy 20 bài thật (hoặc ghi rõ "Chưa chạy — chưa có API key" nếu thiếu key), report có bảng số thật; 100% đầu ra qua schema `ArticleVersion` và `HtmlSanitizer`.
- **Test:** `AiProviderFactoryTest`, `GeminiResponseInvalidTest` (không fallback), `PromptVersionHashTest`.

#### S0.2 — Sửa bảng tài khoản/credential mẫu (08/11, 2h — phối hợp Dev1)

- Seed chỉ ở `local`; mật khẩu sinh ngẫu nhiên in ra console 1 lần; credential = placeholder.

> Phần còn lại của `HtmlSanitizer`, `schedule` (E3, E4) nằm ở task Dev1 — Dev2 **chỉ viết test đỏ** (`tests/Unit/HtmlSanitizerSecurityTest`) trong task 3.5 để chặn merge.

#### S0.3 — 0.5 Filament panel tiếng Việt (10/11 → 11/11; 10h) — *một phần đã có từ `33c4872`*

| Hạng mục | Công việc | Giờ |
|---|---|:--:|
| 0.5.1 | Panel: `locale('vi')`, `brandName`, `->maxContentWidth`, menu order, `->databaseNotifications()` nếu bật | 1 |
| 0.5.2 | Chuẩn dữ liệu test theo `contracts/WebsiteConnection` + fixture 3 site (WP-staging, WP-prod-like, Magento-staging) | 2 |
| 0.5.3 | `WebsiteResource`: che credential (chỉ hiện "Đã lưu – cập nhật"), trường `seo_plugin` trong `capability_flags`, nút **Kiểm tra kết nối** (gọi `PublisherInterface::testConnection`; mock khi chưa có) | 3 |
| 0.5.4 | Trạng thái website: badge + tooltip lý do `revoked/error`; chặn xoá khi còn Publication đang `scheduled/publishing` | 2 |
| 0.5.5 | Test: `WebsiteResourceTest` (render danh sách, credential không lộ trong HTML, validation URL chỉ HTTPS) | 2 |

**AC:** F-02 — thêm website WP, credential không hiển thị rõ, bấm kiểm tra kết nối trả về trạng thái có log.
**Nhánh:** `feat/m01/0-5/dev2/filament`. **Bằng chứng:** screenshot + Pest test output.

---

### GIAI ĐOẠN 1 — V1.0 (12/11 → 20/11, ~34h)

#### G1.1 — 3.4 Giao diện duyệt bài (12/11 → 13/11; 10h)

- Trang **Chi tiết bài** 2 cột: trái = `ArticleVersion` (render HTML đã sanitize trong iframe `sandbox`), phải = metadata (title/slug/seo_title/meta/focus keyword) + validator cảnh báo (độ dài, trùng), **lịch sử phiên bản** (diff cũ→mới), **người duyệt + thời điểm + ghi chú**.
- Action state-machine theo `WorkflowEnum`: `Gửi duyệt (needs_review)`, `Duyệt (approved, gán version đã duyệt)`, `Từ chối (rejected + lý do bắt buộc)`, `Xếp lịch` chỉ bật khi `approved`.
- Màn **Cấu hình chiến dịch**: tổng số bài, số bài/ngày, số ảnh/bài, giờ đăng (VN), ngày bắt đầu → hiển thị **preview lịch** (danh sách `scheduled_at_utc` + giờ VN) trước khi lưu.
- Bảng bài viết: filter theo website/trạng thái/ngày; cột lỗi (`last_error` của Publication) badge đỏ; **không** có action publish trực tiếp (xuất bản là worker của Dev1).
- **Phụ thuộc mềm:** schema `ArticleVersion` thực tế; các cột `approved_version_id`, `campaign` cần migration → **PR contract riêng do Dev1 mở**, Dev2 chỉ dùng sau khi merged; nếu chưa có thì dùng cột `review_status` + bảng `publications` hiện tại và ghi nợ tech-debt.
- **Test:** `ArticleReviewFlowTest` (writer không thấy nút Duyệt; approver duyệt được; publish không bật khi rejected); `CampaignSchedulePreviewTest`; `FilamentAuthorizationTest`.
- **AC:** F-09, F-10, AC01. **Nhánh:** `feat/m03/3-4/dev2/review-ui`.

#### G1.2 — 1.2 WordPress Connector (14/11 → 18/11; 16h)

| Bước | Việc | Giờ | AC |
|---|---|:--:|---|
| WP01 | `WordPressClient`: Basic Auth `user:app_password`, `Accept/UA`, timeout 20s, **retry chỉ với 429/5xx GET**, log đã che header | 2 | — |
| WP02 | `testConnection`: `GET /wp-json/` → version, plugin list (best-effort), version ≥ 5.6 (Application Passwords), **fail rõ nếu HTTP** | 1 | F-02 |
| WP03 | `uploadMedia` qua `/wp/v2/media`: MIME thật (finfo), size ≤32MB, tên file normalize, đặt `alt_text`, trả `id/source_url/hash` | 3 | AC07 |
| WP04 | `createDraft` qua `/wp/v2/posts` `status=draft`, map `title/content/slug/categories/tags/featured_media`, **dùng `publication_key` do publisher cấp** (lưu vào meta qua bridge, **không tự sinh** như E9), lưu `remote_id` **ngay** | 3 | AC01/AC02 |
| WP05 | `update` + conflict: so sánh `modified_gmt` với lần đọc trước → nếu khác → `ConflictException` (không ghi đè) | 2 | WP05 |
| WP06 | `SeoMetaAdapter`: `RankMathAdapter` (meta `rank_math_*`) / `YoastAdapter` (`yoast_head_json` + meta key) — **chọn 1 plugin thật theo staging**; ghi meta → **đọc lại** → so khớp; không ghi được → báo `metadata_readback_failed` (không giả lập đạt) | 3 | WP06 |
| WP07 | `ErrorMapper`: map `ClientException/ServerException/ConnectException/JSON sai` → `CmsError` ERR01–ERR07; **không ném exception thô** (E11). Đưa `CmsError` vào `app/Contracts` — **Dev1 mở PR** để Magento dùng lại | 1 | QA07 |
| WP08/09 | `getByPublicationKey`: tra meta `_autoseo_publication_key` qua `/wp/v2/posts?meta_key=…` hoặc REST field bridge (nếu plugin SEO chiếm quyền meta thì tạo **mu-plugin nhỏ ≤40 dòng** trong `app/Integrations/WordPress/Bridge/`); không tìm thấy → trả `null` → Dev1 đánh `needs_reconcile` | 2 | AC04 |
| — | Contract test bằng `MockHandler` (giống mẫu `MagentoBridgeClientTest`) cho **7 lỗi** (401/403/404/429/5xx/timeout/JSON sai) + 1 test tích hợp thật (đánh dấu `@group live`, skip nếu thiếu env) | 2 | QA07 phần WP |

- **Không** được: retry mù POST tạo bài; log credential; dùng ID category/media site khác (AC02 — kiểm `website_id` của media trước khi gắn).
- **Hoàn thành khi:** 3 bài nháp thật trên staging WP, đọc lại title/content/ảnh/meta, có URL — hoặc ghi **"Bị chặn tích hợp"** nếu staging chưa cấp.
- **Nhánh:** `feat/m02/1-2/dev2/wordpress`.

#### G1.3 — 3.5 QA nội dung cơ bản (19/11 → 20/11; 8h) · *phụ thuộc pipeline sửa xong 16/11*

- `ContentQualityChecker` chạy **server-side** trước khi cho `approved`; trả `RuleResult{id, severity(P0/P1/P2), message_vi}`.
- Rule v1: **R01** độ dài 800–3.000 từ · **R02** ≥2 H2, không nhảy cấp H2→H4 · **R03** từ khóa chính trong title + `seo_title` + ≥1 H2 + đoạn đầu · **R04** meta description 120–160 · **R05** trùng lặp nội bộ (shingle 5-gram Jaccard > 0,35 với các version khác cùng website) · **R06** link hỏng/không thuộc allowlist domain · **R07** bài chưa duyệt/thiếu nguồn (`sources_cited` rỗng) không được duyệt · **R08** HTML `style`/`script`/`iframe` còn sót (test đỏ cho E3).
- Tích hợp: thẻ "Chất lượng" trên màn duyệt, chặn nút Duyệt khi còn P0 (override được bởi `approver` + lý do ghi log).
- **Test:** mỗi rule ≥2 case dương + 1 case âm; `QualityGateBlocksApprovalTest`.
- **AC:** F-11 "bài thiếu heading hoặc trùng nội dung bị đưa về chờ review".
- **Nhánh:** `feat/m03/3-5/dev2/quality`.

#### G1.4 — Hỗ trợ nghiệm thu V1.0 + review PR Dev1 (20/11; 4h)

- Điều kiện gốc V1.0 (19/11): "3 bài mẫu; đọc lại bài/ảnh/meta; CI bảo mật trước pilot". Dev2 kiểm **cả 4 mức bằng chứng** cho 0.2/0.4/3.1 + ký nghiệm thu F-01→F-03, F-07, F-09→F-11. Nếu chưa đủ → đề xuất lùi mốc V1.0 sang **23/11** (không tự đánh dấu hoàn thành).

---

### GIAI ĐOẠN 2 — V1.1 hỗ trợ (21/11 → 28/11, ~8h + 2h 3.6)

#### G2.1 — 2.1 Nhập từ khóa & gom cụm AI (21/11 → 23/11; 8h)

- Upload CSV (UTF-8/BOM, `keyword,intent,priority,volume|note`), validate & preview 20 dòng, dedupe, giới hạn 5.000 dòng/lần.
- `KeywordClusterer`: gửi theo batch 100 từ khóa → AI trả `{cluster, intent, primary, secondary[]}`; lưu `keyword` + `cluster` + `source=manual|csv`; người dùng **sửa/merge/split cluster** trước khi lưu.
- **Không bịa search volume** — cột volume để trống/`null` nếu CSV không có (đúng DOC16).
- **Nhánh:** `feat/m04/2-1/dev2/keywords`; **AC:** F-05 (nhập 100 từ khóa, duyệt nhóm gợi ý).

#### G2.2 — 2.2 Kế hoạch nội dung (23/11 → 24/11; 10h)

- `ContentPlanBuilder`: từ cluster → sinh danh sách bài (tên, từ khóa chính, intent, độ ưu tiên, ngày dự kiến) theo **quota số bài/ngày** của chiến dịch; lịch dời được (kéo thả = đổi ngày/đổi cluster), có undo.
- Gắn vào `Article.workflow_status=planned`; khi "Kích hoạt chiến dịch" → tạo `Article` + (qua Dev1) `Publication` dự kiến.
- **Nhánh:** `feat/m04/2-2/dev2/content-plan`; **AC:** F-06.

#### G2.3 — Huy động cho 3.6 + review 3.2/3.3/3.6 (27/11 → 28/11; 2h + ≤4h)

- Dev2 kiểm AC02/AC04/AC05/AC06 bằng bộ ca lỗi QA07 (timeout-sau-tạo, 2 worker, restart, thu hồi credential) và **không công nhận** kết quả nếu chỉ HTTP 200.
- **Ca riêng cho Magento (1.4):** sau khi Dev1 sửa E9/E10, chạy lại ca "timeout ngay sau `createDraft` rồi retry" trên cả WP và Magento — **chỉ đúng 1 bài** trên mỗi CMS. Trước khi sửa, ca này **sẽ fail** (key mismatch) — ghi thành bug issue, không nghiệm thu 3.6.

---

### GIAI ĐOẠN 3 — V1.2 Audit & bàn giao (25/11 → 09/12)

#### G3.1 — 1.3 Báo cáo audit SEO tiếng Việt (25/11 → 27/11; 8h) · *phụ thuộc 1.1 của Dev1*

- Nhận dữ liệu crawl thô (Dev1: URL, HTTP, redirect, robots, meta, canonical, headings, ảnh thiếu alt, schema) → `RuleEngine` chấm theo **18 chỉ số DOC02**, gắn P0/P1/P2, **lưu URL + giá trị quan sát được** (không chấm điểm trống).
- `AiSuggestionsService`: sinh đề xuất tiếng Việt **chỉ từ dữ liệu quan sát** (dữ liệu crawl là input, không phải lệnh — chống prompt injection), mỗi đề xuất có `source_url` + `rule_id`; dòng "không đủ dữ liệu" thay vì bịa.
- UI: báo cáo theo website, filter severity, xuất CSV; **không có chức năng sửa trang**.
- **Nhánh:** `feat/m04/1-3/dev2/audit-report`; **AC:** F-16, DOC02 review.

#### G3.2 — Pilot 4.1 (04/12 → 08/12, mỗi ngày 2h) + 4.2 (09/12, 6h)

- 4 ngày quan sát: mỗi ngày ghi log `docs/evidence/4-1/dev2-dayN.md` — số bài tạo, số duyệt, số đăng, lỗi (`publication_key`, `remote_id`, URL), khôi phục sự cố.
- 4.2: sửa lỗi cụm trên UI/connector, tinh chỉnh prompt v2 dựa log pilot, viết **hướng dẫn Marketing** (`docs/handover/HUONG_DAN_MARKETING.md`) + ảnh chụp từng bước; tập huấn 1 giờ; Marketing ký AC13.

---

### GIAI ĐOẠN 4 — V2 (01/12 → 25/12, 72h)

| Task | Ngày | Giờ/ngày | Nội dung then chốt | Nhánh |
|---|:--:|:--:|---|---|
| **5.1 GSC** | 01/12 → 03/12 | 6h×3 | `GscClient` dùng `webmasters.readonly`; OAuth refresh token **mã hóa**; đồng bộ Search Analytics (date PT, `rowLimit` ≤25.000, phân trang `startRow`); UI so sánh 2 kỳ × filter page/query/device/country; **CTR = SUM/SUM**, impressions=0 → "chưa có CTR"; lưu `dataState` và ghi rõ dữ liệu thiếu | `feat/m07/5-1/dev2/gsc` |
| **5.4 Đối thủ** | 10/12 → 13/12 | 3h/ngày | Nhập danh sách đối thủ (≤5), crawl sitemap qua **crawler an toàn của Dev1**; bản đồ chủ đề; API từ khóa trả phí (DataForSEO) **sau ngân sách được duyệt**, nếu chưa → trạng thái "Chưa cấu hình" + dùng nguồn nội bộ | `feat/m07/5-4/dev2/competitor-gap` |
| **6.1 QA nâng cao** | 14/12 → 17/12 | 6h/ngày | Trùng lặp internet: đoạn 8-gram tra qua nguồn **cho phép** (chỉ đối thủ + sitemap công ty, không scrape Google); dữ kiện: AI trích `claim` → đối chiếu `sources_cited` → cờ "thiếu nguồn"; giọng thương hiệu: checklist + AI chấm 1–5 | `feat/m07/6-1/dev2/quality-plus` |
| **6.4 RBAC/đa thương hiệu** | 18/12 → 21/12 | 3h/ngày | `Brand`, `Department`, `brand_user`, `website_user` (migration do Dev1); Filament `ScopedToWebsite` trait + kiểm tra server-side; test âm tính chéo brand | `feat/m08/6-4/dev2/brands-rbac` |
| **7.2 Ổn định** | 22/12 → 25/12 + 28/12 | 2h | Quan sát + sửa lỗi cuối; đủ **5 ngày quan sát** theo AC7.2 (22–25/12 + 28/12) | `test/m08/7-2/dev1/stability` (Dev1 mở, Dev2 tham gia) |

---

## 5. Timeline ngày (Dev2, 6h/ngày, T2–T7)

| Ngày | Thứ | Chính | Giờ | Ghi chú |
|---|---|---|:--:|---|
| 06/11 | T6 | S0.0 Kickoff + chốt interface/ERR + xin dữ liệu | 2 | ⚠️ trễ so với sheet; đã dồn |
| 07/11 | T7 | 0.3 (prompt v1 + PromptRegistry) | 4 | Buffer |
| 08/11 | T7 | 0.3 (đo 20 bài + báo cáo chi phí) + S0.2 seed/credential | 4 | |
| 09/11 | T2 | 0.3 chốt | 6 | **0.3 due 09/11** |
| 10/11 | T3 | 0.5 (panel + website + test connection) | 6 | |
| 11/11 | T4 | 0.5 hoàn tất + test + review 0.4 | 4 + 2 | **0.5 due** |
| 12/11 | T5 | 3.4 màn duyệt + cấu hình chiến dịch | 6 | |
| 13/11 | T6 | 3.4 lịch + policy test + review | 4 + 2 | **3.4 due** |
| 14/11 | T7 | 1.2 WP client + media | 6 | **1.2 bắt đầu thật** |
| 16/11 | T2 | 1.2 draft/update/SEO meta | 6 | |
| 17/11 | T3 | 1.2 contract test 7 lỗi + tích hợp staging | 6 | |
| 18/11 | T4 | 1.2 chốt + review 3.1 | 4 + 2 | **1.2 due** |
| 19/11 | T5 | 3.5 checker R01–R05 | 6 | |
| 20/11 | T6 | 3.5 R06–R08 + gate + nghiệm thu V1.0 | 6 | **3.5 due · V1.0 20/11** |
| 21/11 | T7 | 2.1 CSV + cluster AI | 6 | |
| 23/11 | T2 | 2.1 chốt + 2.2 builder | 2 + 4 | **2.1 due** |
| 24/11 | T3 | 2.2 lịch kéo thả + kích hoạt | 6 | **2.2 due** |
| 25/11 | T4 | 1.3 RuleEngine + UI | 6 | |
| 26/11 | T5 | 1.3 AI đề xuất + CSV | 6 | |
| 27/11 | T6 | 1.3 chốt + review PR Dev1 (1.1) | 4 + 2 | **1.3 due 27/11** |
| 28/11 | T7 | Review 1.4/3.2/3.3/3.6 + ca lỗi QA07 | 6 | **V1.1 due** |
| 30/11 | T2 | Review V1.1 nghiệm thu + bù 1.3 | 6 | |
| 01/12 | T3 | 5.1 OAuth + sync | 6 | |
| 02/12 | T4 | 5.1 UI so sánh kỳ | 6 | |
| 03/12 | T5 | 5.1 test + báo cáo | 6 | **5.1 due** |
| 04/12 | T6 | 4.1 pilot ngày 1 | 2 | |
| 05/12 | T7 | 4.1 ngày 2 | 2 | |
| 07/12 | T2 | 4.1 ngày 3 | 2 | |
| 08/12 | T3 | 4.1 ngày 4 | 2 | **4.1 due** |
| 09/12 | T4 | 4.2: sửa lỗi + hướng dẫn | 6 | **V1.2 due** |
| 10/12 | T5 | 4.2 tập huấn + Marketing nghiệm thu × 3h; 5.4 bắt đầu × 3h | 6 | |
| 11/12 | T6 | 5.4 sitemap đối thủ + bản đồ chủ đề | 6 | |
| 12/12 | T7 | 5.4 API từ khóa / gap analysis | 6 | |
| 13/12 | T7 | 5.4 chốt + test | 2 | **5.4 due** |
| 14/12 | T2 | 6.1 trùng lặp internet | 6 | |
| 15/12 | T3 | 6.1 kiểm dữ kiện | 6 | |
| 16/12 | T4 | 6.1 giọng thương hiệu + UI | 6 | |
| 17/12 | T5 | 6.1 test + chốt | 2 | **6.1 due** |
| 18/12 | T6 | 6.4 migration quyền + Brand/Department | 6 | |
| 19/12 | T7 | 6.4 ScopedToWebsite + test âm tính | 6 | |
| 21/12 | T2 | 6.4 chốt + review PR V2 Dev1 | 4 | |
| 22/12 | T3 | 7.2 ngày 1 + sửa lỗi | 2 | |
| 23/12 | T4 | 7.2 ngày 2 | 2 | |
| 24/12 | T5 | 7.2 ngày 3 | 2 | |
| 25/12 | T6 | 7.2 ngày 4 + bàn giao tài liệu | 4 | |
| 28/12 | T2 | 7.2 ngày 5 (quan sát đủ) | 2 | Chốt V2 |

Tổng Dev2 trong bảng: **~188h** (khớp sheet: 188h).

---

## 6. Điểm đổi API / schema / luồng dữ liệu (public surface)

| Hạng mục | Thay đổi | Ai thực hiện | Ghi chú |
|---|---|---|---|
| `PublisherInterface` | Đổi `createDraft/publish` → trả `PublisherResult{remote_id, url, state, key_found}` + `throw CmsError(code)`. **Phá vỡ** nếu chỉ giữ array | **Dev1** (contract PR) | Dev2 implement WP theo FINAL |
| `contracts/WorkflowEnum.json` | Thêm `scheduled`, `published`; đổi `status` → `review_status` | Dev1 + Dev2 review | E5 |
| Migrations mới cần | `ai_provider_runs`, `keywords`, `keyword_clusters`, `campaigns/content_plans`, `seo_audit_reports`+`items`, `gsc_metrics`, `brands`, `departments`, pivot brand/website | **Dev1** mở PR, Dev2 review | Dev2 chỉ đọc/ghi qua model được giao |
| Config mới | `config/services.php`: `google_ai`, `gsc`, `dataforseo`, `wordpress` timeout; `config/seo.php`: ngưỡng rule | Dev1 | Dev2 đọc qua `config()` |
| Route web | Chỉ `/admin`; **không** thêm route public; health ở Dev1 | — | — |
| Job mới (5.1/6.1) | `SyncGscMetrics`, `ScanInternalDuplication` | `app/Jobs` thuộc Dev1 → Dev2 đặt **logic vào `app/Services/Seo/**`**, Dev1 chỉ tạo job mỏng gọi service | Tránh xung đột allowlist |

---

## 7. Rủi ro & cách chặn

| Rủi ro | Xác suất | Tác động | Biện pháp |
|---|---|---|---|
| Staging WP/plugin SEO/Magento chưa sẵn | Cao | 1.2 không nghiệm thu được | Hạn chót xin dữ liệu **11/11**; nếu trễ, giao mức mock + nhãn "Bị chặn tích hợp"; Dev1 chịu trách nhiệm QA02 |
| Pipeline 3.1 lỗi (E1/E2) | Cao | 3.5/2.1/1.3 sai gốc | Issue conformance; test đỏ chặn merge; không viết UI trên dữ liệu chưa đạt |
| 1.1 crawler (Dev1) trễ | Trung bình | 1.3 trễ | Định nghĩa interface dữ liệu crawl (`AuditPageData`) từ 20/11 và làm UI/mock trước; Dev1 chỉ cần trả DTO |
| Ngân sách API từ khóa/GSC chưa duyệt | Trung bình | 5.4/5.1 thiếu số | Trạng thái "Chưa cấu hình" rõ ràng; không bịa số volume |
| Trùng nhánh migrations/contracts với Dev1 | Trung bình | Xung đột merge | Quy tắc §2.5; one-owner-per-file |
| Tính 6h/ngày & 6 ngày/tuần quá tải | Trung bình | Trễ mốc | Cắt scope phụ (FAQ/schema nâng cao, video hướng dẫn) — không cắt test/AC |
| Dev2 vừa build vừa review PR Dev1 | Thấp | Trễ | Block 2h đầu mỗi ngày có PR của Dev1 để review, không chen ngang khi đang code |

---

## 8. Định nghĩa hoàn thành (DoD) cho mọi task

1. Code đúng allowlist, PSR-12 (`./vendor/bin/pint --test`), không chứa secret, log đã che.
2. ≥1 Feature/Unit test cho mỗi AC; `php artisan test` xanh; CI xanh ở **đúng SHA**.
3. Có bằng chứng ở mức phù hợp: mock / staging có URL-ID / người thật nghiệm thu.
4. PR có: mã task, file đổi, AC map, phần **Chưa chạy** rõ ràng, rủi ro, migration/rollback (nếu có).
5. Cập nhật `TASKS.md` + sheet 7 + `reports/daily/*.md` kèm SHA.
6. Dev1 review chéo và merge; Dev2 xoá nhánh.

---

## 9. Assumption (kèm cách xử lý lỗi)

1. Ngày bắt đầu làm thật của Dev2 là **06/11/2026** (kế hoạch gốc 03/11 → độ trễ 2 ngày đã được dồn).
2. "Giờ theo dự kiến" = giờ người có AI hỗ trợ (6h/ngày), **không** cộng thời gian AI chạy.
3. Staging **WordPress và Magento là website thật của công ty** — Dev2 không tự dựng CMS.
4. Plugin SEO sẽ là **Rank Math hoặc Yoast**, chốt tại S0.0 — nếu không plugin nào ghi qua REST được meta, phương án B là **mu-plugin bridge ≤40 dòng** (nằm trong `app/Integrations/WordPress/Bridge/`, Dev1 review).
5. OAuth GSC & DataForSEO **tuỳ ngân sách**; chưa có → hiển thị "Chưa cấu hình", không làm giả.
6. Mọi thay đổi DB/config/interface **chờ Dev1**; nếu chờ >3 ngày, ghi blocker trong báo cáo ngày và chuyển sang task không phụ thuộc.
7. Nếu sheet `7_Bao_cao` / `TASKS.md` tiếp tục ghi "Hoàn thành" cho 0.2/0.4/3.1 trong khi chưa có bằng chứng, Dev2 đề nghị quản lý hạ về **"Chờ review"** — không tự sửa trạng thái của Dev1.

---

## 10. Việc cần quyết trước khi bắt đầu

| # | Cần ai | Nội dung | Hạn |
|---|---|---|---|
| Q1 | Dev1 | `PublisherInterface` FINAL + bảng mã lỗi ERR01–ERR07 + `CmsError`/`ErrorMapper` dùng chung | 12/11 (dùng được từ 14/11) |
| Q2 | Dev1 | Sửa pipeline 3.1: bỏ fallback Mock im lặng, capture `prompt_version`, bọc `AiProviderFactory` | 16/11 |
| Q3 | Dev1 | Publish policies + `authorize()` cho Filament, che credential WebsiteResource | 11/11 |
| Q4 | Dev1 | `php artisan autoseo:publish-due-posts` thật hoặc tắt schedule | 20/11 |
| Q5 | Quản lý/Dev1 | Staging URL WP + Application Password + plugin SEO; GSC property; key Gemini | 11/11 |
| Q6 | Quản lý | Ngân sách DataForSEO & quota GSC | 30/11 |
| Q7 | Dev2 | Chọn plugin SEO V1 (Rank Math vs Yoast) sau khi thấy staging | 12/11 |
| Q8 | Marketing | Nguồn tài liệu/ảnh công ty + domain allowlist | 14/11 |
| Q9 | Cả hai | Hạ trạng thái 0.2/0.4/3.1 **và 1.4** về "Chờ review" đến khi đủ AC08/SEC02/SEC03/SPEC08/QA02 | 06/11 |
| Q10 | Dev1 | Sửa Magento E9/E10: dùng `publication_key` hệ thống cấp, không tự tạo draft trong `publish()` | 20/11 (trước 3.6) |
| Q11 | Dev2 | Bổ sung test Magento 401/403/429/5xx/timeout (E12) trong PR conformance — hoặc ghi nợ kèm owner | 20/11 |

---

## 11. Tiêu chí nghiệm thu tổng của Dev2 theo mốc

- **V1.0 (20/11):** F-02, F-07 (PoC), F-09→F-11 pass trên staging; 3 bài mẫu WP có URL đọc lại; UI duyệt chặn bài lỗi; không lộ credential trong HTML/log.
- **V1.1 (28/11):** F-12→F-14 được Dev2 kiểm bằng ca lỗi QA07, có log `publication_key` chứng minh 0 trùng **trên cả WordPress và Magento** (F-04 Magento chỉ đạt khi hết E9/E10 + có ca lỗi, không chỉ happy-path mock).
- **V1.2 (09/12):** F-05/F-06/F-15/F-16 pass; báo cáo audit có URL + đề xuất; 4 ngày pilot log đủ; Marketing tự vận hành và ký AC13.
- **V2 (28/12):** F-17/F-20/F-21/F-24 pass; 5 ngày quan sát không lỗi P0/P1; toàn bộ tài liệu bàn giao + hướng dẫn Marketing hoàn tất.
