# KẾ HOẠCH CẢI TẠO GIAO DIỆN THEO THIẾT KẾ "SEO STUDIO" (Dev2)

> Lập 06/11/2026 · Cơ sở: `origin/main` = `origin/develop` = `9d39b90` (đã kéo về, fast-forward 7 commit) · Thiết kế: `../design-system/` (DESIGN_BRIEF.md + 8 mockup + tokens/components CSS) · Kế hoạch trước: `DEV2_UI_PLAN.md` (giữ làm nguồn phát hiện U1–U9, tài liệu này **thay thế phần thứ tự và cách làm**).
> Đây là **kế hoạch thiết kế**, chưa có dòng code nào được sửa. Mức bằng chứng của mọi mục dưới đây là "đã đọc code / đã chạy test hiện có", chưa phải "đã dựng thử".

---

## 0. Hiện trạng đã kiểm chứng (không suy đoán)

| Mục | Kết quả | Cách kiểm |
|---|---|---|
| Git | Local `main` chậm 7 commit → đã `pull --ff-only` về `9d39b90`. File `bootstrap/cache/*.php` đang sửa dở không bị đụng. 3 file `docs/plan/DEV2_*` vẫn untracked. Có thêm nhánh `feat/m01/0-3/dev2/ai-poc`, `feat/m03/3-3/dev1/media` (đã merge). | `git fetch`, `git diff --stat` |
| Docker | 4 container chạy, `/admin/login` trả 200. | `docker ps`, `curl` |
| Test nền | **42 xanh, 6 đỏ** (không phải "21 xanh" như plan cũ). 6 đỏ đều do PHP trong image **không có `imagewebp`** (GD thiếu WebP) → task 3.3 của Dev1: `ImageOptimizerTest`, `MediaServiceTest`. | `docker compose exec app php artisan test` |
| Chưa có | `app/Policies/`, `app/Enums/`, `database/factories/`, `package.json`/Vite/Tailwind, bảng `campaigns`/`keywords`, test cho bất kỳ Filament Resource nào. | `ls` |
| Filament | `filament/filament v3.3.57`, Livewire 3.8, Laravel 11.57. Chưa cài plugin nào. | `composer.lock` |
| Mới từ Dev1 | `MediaAssetResource` (Thư viện ảnh), bảng `media_assets` + pivot `article_version_media`, `ArticleVersion.mediaAssets()`, `cost_vnd` + `TokenCostCalculator`, prompt v1.0. | diff `1e24d72..9d39b90` |
| U9 đã xác nhận | `PublishArticleJob.php:64` — website không `active` ⇒ publication `failed`. Cột `publications.status` là **DB enum** (5 giá trị) ⇒ thêm `held` cần migration (Dev1). | đọc code + migration |

**Hai phát hiện mới so với plan cũ:**
1. **Hai máy trạng thái song song:** `articles.workflow_status` (7 giá trị trong form, 10 trong plan) và `article_versions.review_status`. `ContentPipelineService` ghi cả hai (`:34,68,72`). Màn duyệt phải chọn **một nguồn sự thật** (đề xuất: `Article.workflow_status` điều hướng, `ArticleVersion.review_status` ghi quyết định theo từng bản) – nếu không, nhãn trên danh sách và trên màn duyệt sẽ mâu thuẫn.
2. **Chi phí AI đã là VND** (`cost_vnd`, `TokenCostCalculator`) ⇒ trả lời sẵn câu hỏi "VND hay USD" của brief: **dùng VND**, USD chỉ hiển thị phụ nếu quản lý yêu cầu.

---

## 1. Kiểm kê: giữ gì, đổi gì, thêm gì

| Phần hiện có | Quyết định | Lý do / liên kết |
|---|---|---|
| `WebsiteResource` (form + bảng) | **Cải tạo tại chỗ** | Giữ model, route, label VN. Đổi: credential, trạng thái, xóa→thu hồi, thêm slide-over chi tiết (mockup `websites.html`). |
| `ArticleResource` (+ action `generate_ai`) | **Cải tạo, giữ action AI** | Giữ `generate_ai` và `ContentPipelineService`. Bỏ ô chọn trạng thái; tách thành "Chiến dịch" (nhóm) → "Bài viết" (workspace). |
| `PublicationResource` | **Đổi thành màn Lịch đăng chỉ-đọc + action** | Khóa tạo/sửa tay; giữ bảng, thêm hàng đợi/lỗi/đối soát. Gọi `ReconcilePublicationJob` có sẵn. |
| `MediaAssetResource` (Dev1, mới) | **Giữ nguyên chức năng, chỉ xếp lại menu** | Dời vào nhóm Cài đặt; sau này nhúng bộ chọn ảnh vào workshop (qua `ArticleVersion.mediaAssets`). Không sửa logic upload. |
| `AdminPanelProvider` | **Cấu hình lại** | Màu, font, nhóm menu, tìm kiếm Ctrl+K. File này nằm ngoài allowlist chữ nghĩa của AGENTS.md → xem §6 Q1. |
| Widget mặc định (Account, FilamentInfo) | **Thay** | Bằng Tổng quan 3 widget. |
| `ContentPipelineService`, Jobs, Publisher, Magento | **Không đụng** | Chỉ đọc kết quả. UI chỉ gọi service/job qua action. |
| Mockup có, hệ thống chưa có | **Làm sau, theo task sở hữu** | campaigns, keywords, content-plan, audit, GSC, đối thủ, ai-usage cần bảng mới (Dev1). |
| Mockup `styleguide`, `flows` | **Không port** | Là tài liệu thiết kế, không phải màn hình. |

---

## 2. Tận dụng framework – cái gì dùng sẵn, cái gì phải tự làm

Nguyên tắc: Filament 3.3 làm được bằng cấu hình thì không viết Blade/JS riêng; **không thêm composer dependency** (cần Dev1 duyệt `composer.json`) nếu 50 dòng PHP giải quyết được.

| Nhu cầu trong thiết kế | Cách làm | Phụ thuộc mới? |
|---|---|---|
| Màu teal `#0F6B5C`, tên "SEO Studio", font | `Panel::colors(['primary'=>Color::hex(...)])`, `brandName`, `font()` | Không |
| Token màu/khoảng cách/radius của mockup | Một file `resources/css/seo-studio.css` (hoặc `public/css`) nạp bằng `FilamentAsset::register([Css::make(...)])` **hoặc** `renderHook(HEAD_END)` — **không cần Vite/Tailwind**. Chỉ ghi đè biến `--primary-*`, `--gray-*` của Filament + vài class (badge, hairline). | Không (chưa có `package.json`; chỉ lên Vite nếu CSS vượt ~300 dòng – quyết ở Đợt 1 sau khi thử) |
| 6 mục điều hướng + nhóm | `navigationGroups()`, `navigationSort`, `navigationLabel`, `navigationBadge` (số "Lịch đăng: 4") | Không |
| Ctrl/⌘K | `globalSearchKeyBindings(['ctrl+k','command+k'])` + `getGlobalSearchResultsLimit`. Palette *hành động* ("Duyệt bài đang mở") **không có sẵn** → để V1.2, V1 chỉ tìm kiếm | Không |
| Badge trạng thái thống nhất | Lớp `app/Filament/Support/StatusPresenter` (map chuỗi → nhãn/màu/icon) dùng cho mọi cột. Khi Dev1 thêm PHP Enum (`HasLabel/HasColor`) thì thay ruột, giữ giao diện lớp. | Không |
| Slide-over chi tiết website | `ViewAction`/`Action::slideOver()` + `Infolist` | Không |
| Duyệt/Từ chối/Gửi duyệt/Xếp lịch | `Action::make()->visible()/authorize()` + `form([...])` (lý do bắt buộc) + `requiresConfirmation()`; **hai action riêng** | Không |
| Hộp xác nhận "Thu hồi" có số bài ảnh hưởng | `modalDescription(fn)` + `form()` | Không |
| Xem HTML bài AI | `Infolist` + `<iframe sandbox srcdoc>` (đã qua `HtmlSanitizer`) | Không |
| Diff giữa hai bản | Class `ParagraphDiff` tự viết (LCS theo đoạn). `sebastian/diff` chỉ có ở **require-dev**, không dùng được trên production | Không (tránh `jfcherng/php-diff`) |
| Lịch tuần/hàng đợi | Trang Livewire tự viết (lưới 7 cột bằng Blade) – mockup rất tuỳ biến, plugin lịch khó khớp | Không |
| Dashboard | `StatsOverviewWidget`, `TableWidget`, `ChartWidget` (Chart.js đi kèm) | Không |
| Thông báo | `Filament\Notifications` (đang dùng ở `generate_ai`); DB notifications = 6.3 (Dev1) | Không |
| Phân quyền | `Resource::canViewAny/canEdit/...` dựa `User::isAdmin/isApprover/isWriter` **ngay**; chuyển sang Policy khi Dev1 giao | Không |
| Kiểm thử UI | `Livewire::test()` cho Resource/Action (không cần browser) + 1 lượt chụp màn bằng trình duyệt tích hợp | Không |
| Dark mode | Filament có sẵn; đồng bộ token dark của mockup ở cùng file CSS | Không |

---

## 3. Bản đồ phụ thuộc giữa các phần (suy luận liên kết)

```
 [Dev1] PR-A: sửa GD/WebP (6 test đỏ) ───────────────► mọi PR UI mới có "CI xanh"
 [Dev1] PR-B: Policies 3 vai trò ────► (tạm thay bằng canX trong Resource) ──► W1,W2
 [Dev1] PR-C: migration lô A (campaigns, articles.campaign_id,
              approved_version_id, publications 'held', nhật ký kết nối,
              bảng audit_logs) ──► W2(f,g), W3 (log truy vấn được), W5
 [Dev1] PR-D: PublisherInterface FINAL + CmsError ──► W1 bước "Kiểm tra kết nối", W2 "Đối soát"
 [Dev1] PR-E: Model $hidden/casts/enum ──► (không chặn: UI tự che bằng cách không đưa field vào form)

 W0 plan/docs ─► W1 Shell+Khóa P0 ─► W2 Website ─► W3 Bài viết+Duyệt ─► W4 Lịch đăng
                                  └──────────────────► W5 Chiến dịch+Tổng quan
 W3 ─► W6 Chất lượng (3.5)         W7 Discover (2.1,2.2)         W8 Phân tích (1.3,5.1,5.4)
```

Các quan hệ then chốt:
- **Nhãn trạng thái (W1) phải có trước mọi màn** vì Website, Bài, Lịch dùng chung → làm `StatusPresenter` trước, không làm riêng từng màn.
- **Khóa U2/U3 (W1) phải trước màn duyệt (W3)**: nếu dựng màn duyệt khi ô đổi trạng thái tay còn tồn tại thì luồng duyệt vô nghĩa.
- **Thu hồi website (W2) ↔ Lịch đăng (W4):** thu hồi phải liệt kê và (sau PR-C) chuyển các publication `scheduled` sang `held`; đến lúc đó UI chỉ được hiển thị "sẽ bị lỗi" thay vì hứa "tạm giữ".
- **Duyệt (W3) ↔ Xếp lịch (W4):** "Duyệt" ghi `approved_version_id`; "Xếp lịch" chỉ bật khi `approved`. Phiên bản được xếp lịch phải là **chính bản đã duyệt** – nếu người viết sửa sau khi duyệt, bản mới thành `needs_review` và publication cũ phải bị chặn/đánh dấu (cần PR-C).
- **Ảnh (MediaAsset) ↔ Duyệt:** màn duyệt hiển thị ảnh đại diện + alt từ `ArticleVersion.mediaAssets` → thẻ Chất lượng (W6) kiểm alt rỗng.
- **Chiến dịch (W5) ↔ Bài (W3):** `articles.campaign_id` mới; trước PR-C, danh sách "Chiến dịch" gom tạm theo `topic_cluster` (đã có cột) – đánh dấu rõ là tạm, không giả bảng campaigns.
- **Chi phí (`cost_vnd`, `tokens_used`)** nằm ở `ArticleVersion` → hiển thị trong màn duyệt (W3) ngay, màn tổng hợp `ai-usage` là tổng của cùng cột đó (W9), không cần bảng mới cho V1.

---

## 4. Skill giao diện trên máy bạn – nạp vào kế hoạch

Máy có 9 skill UI trong `~/.claude/skills/`. Phát hiện quan trọng: **các skill trỏ tới `/root/ai-design-studio` (script `vqa.mjs`, `a11y.mjs`, `tokens2css.mjs`) – thư mục đó không đọc được từ tài khoản hiện tại (Permission denied)**. Vì vậy:

| Skill | Dùng ở đâu | Cách chạy thực tế |
|---|---|---|
| `ux-strategy` | W0, và trước các màn chưa có mockup (W7, W8) | Chỉ dùng phần quy trình (job của trang, nhãn menu, assumption register). Bộ brief đã có personas → không viết lại. |
| `frontend-design` | W1 (theme) | Brief đã chốt hướng "Editorial Ledger" → **làm theo brief**, chỉ dùng skill để kiểm "có rơi vào mặc định AI không" (đã tránh cream+terracotta, card bo tròn). |
| `design-tokens` | W1 | Quy tắc 3 tầng primitive→semantic→component. **Không chạy `tokens2css.mjs`**; mockup đã có `tokens.css` kèm tỉ lệ tương phản → port biến Filament từ đó và kiểm bằng `design-system/tools/contrast-check.mjs` (có sẵn, đọc được). |
| `visual-qa` | Cuối mỗi đợt có giao diện | Dùng **trình duyệt tích hợp** (`mcp__Claude_Browser__*`) chụp `localhost:8000/admin` ở 375/768/1440, sáng+tối, đọc console. Hoặc `design-system/tools/qa.mjs` (Playwright đã cài trong `design-system/node_modules`). |
| `a11y-audit` | W3 (duyệt bài), W2 (modal), trước PR cuối | Không có `a11y.mjs` → kiểm thủ công bằng trình duyệt tích hợp: Tab order, focus ring, `Esc` đóng modal/slide-over, tương phản đo bằng `contrast-check.mjs`. Ghi rõ "chưa chạy axe". |
| `design-critique` | Cuối W3 và W5 (2 màn trọng yếu) | Chấm so với mockup + brief, cần ảnh chụp thật; trần 74 nếu lỗi tương phản/focus. |
| `design-studio` | Điều phối | Mức **Tier 2 (component)** cho từng Resource, **Tier 3 (page)** chỉ cho Duyệt bài + Tổng quan. Không chạy full pipeline (đã có brief + token). |
| `conversion-design`, `image-assets` | **Không dùng** | Đây là công cụ nội bộ, không phải landing page; ảnh lấy từ `MediaAsset`. |

Mỗi đợt dưới đây ghi rõ skill nào chạy ở bước nào. Báo cáo PR phải nêu: skill/công cụ nào **thật sự chạy**, cái nào "Chưa chạy".

---

## 5. Các đợt thực hiện

Quy ước chung (theo `GITHUB_WORKFLOW.md`): cắt nhánh từ `develop` mới nhất (hiện = `main`), tên `<loại>/<nhóm>/<mã>/<dev>/<slug>`; PR vào `develop`, tiêu đề `[Mã] Tóm tắt`, **squash merge**, Dev1 review chéo, CI xanh; không commit trực tiếp `main`/`develop`. Mỗi PR ≤ ~250 dòng thay đổi logic, để review ≤ 1 giờ. Mọi PR ghi 4 mức bằng chứng (code → mock → staging → người thật) và phần "Chưa chạy".

### Đợt 0 — Chuẩn bị và chốt điều kiện (≈2h, không code)
| | |
|---|---|
| Nhánh | `docs/m01/0-5/dev2/ui-redesign-plan` (commit tài liệu này; hiện 3 file docs đang untracked trên `main`) |
| Việc | (1) Gửi Dev1 danh sách PR-A…PR-E ở §3 và các câu hỏi §6. (2) Chốt bảng nhãn trạng thái §7. (3) Xác nhận allowlist cho `app/Providers/Filament/`, `resources/css|views`, `public/css` (§6 Q1). |
| Xong khi | Dev1 trả lời PR-A, PR-B, PR-C có hạn ngày; bảng nhãn được hai bên đồng ý. |
| Skill | `ux-strategy` (assumption register: chỉ ghi lại A1–A5 của brief, không thêm "nghiên cứu" mới). |

### Đợt 1 — Khóa lỗ hổng P0 + nền nhận diện (task 0.5, ≈6h)
Mục tiêu: sửa lỗi nguy hiểm **trước**, rồi mới đổi diện mạo. Tách 2 PR để lỗi bảo mật không chờ giao diện.

**PR 1a — `fix/m01/0-5/dev2/lock-sensitive-fields`** (≈3h)
| Việc | File (allowlist Dev2) | Test đỏ→xanh |
|---|---|---|
| U2: bỏ `Select workflow_status` khỏi form `ArticleResource`; trạng thái chỉ đổi qua action | `app/Filament/Resources/ArticleResource.php` (+ Pages) | Người viết/duyệt không có field `workflow_status` trong form; POST giả trạng thái `approved` bị bỏ qua |
| U3: `PublicationResource` đặt `canCreate()=false`, form read-only, bỏ `publication_key/status/cms_post_id/url` khỏi sửa; ẩn nút xóa | `PublicationResource.php` + Pages | Không tạo/sửa được; `publication_key` không đổi |
| U1: bỏ `KeyValue encrypted_credentials` khỏi form sửa; hiển thị "•••• đã lưu"; chỉ admin thấy | `WebsiteResource.php` | Dùng `Livewire::test` + `assertDontSee` giá trị mật khẩu mẫu trong HTML trang sửa |
| U4: bỏ `DeleteBulkAction` và nút xóa của Website; chặn xóa khi còn dữ liệu | `WebsiteResource.php` | Xóa bị từ chối; bài/phiên bản còn nguyên |
| U5: `canViewAny/canEdit/canCreate` theo `isAdmin/isApprover/isWriter` | 4 Resource | Ma trận vai trò × màn hình, có ca **âm tính** (writer sửa website → 403) |
| Nền test: `tests/Feature/Filament/` + factory tối thiểu cho test (không đụng `database/`: dùng `Model::create` trong helper test) | `tests/**` | — |
Phụ thuộc: không. **Làm được ngay, không chờ Dev1.**

**PR 1b — `feat/m01/0-5/dev2/filament-shell`** (≈3h)
| Việc | Ghi chú |
|---|---|
| `StatusPresenter` + bảng nhãn §7 | Một nơi duy nhất; các Resource gọi `->badge()->formatStateUsing()->color()` qua nó |
| Panel: màu teal, `brandName('SEO Studio')`, font, `navigationGroups` (Chiến dịch · Lịch đăng · Khám phá · Phân tích · Cài đặt), `globalSearchKeyBindings`, `unsavedChangesAlerts` | `AdminPanelProvider` |
| CSS token (một file) | Cổng thử: nếu ghi đè biến Filament đủ thì không cần Vite |
| Xếp lại menu: Website/Thư viện ảnh → Cài đặt; Bài → Chiến dịch; Lịch xuất bản → Lịch đăng | chỉ thuộc tính tĩnh |
| Thay widget mặc định bằng placeholder tối thiểu (dashboard thật ở W5) | Tránh để trang chủ rỗng |
Skill: `design-tokens` (3 tầng), `frontend-design` (kiểm "có phải mặc định AI"), `visual-qa` (3 viewport sáng/tối, chỉ chụp trang đăng nhập + danh sách), `a11y-audit` thủ công (focus, tương phản).
**Xong khi:** 12 test mới + 42 cũ xanh (trừ 6 đỏ của PR-A, ghi rõ); ảnh chụp trước/sau; `contrast-check` đạt.

### Đợt 2 — Module Website kết nối (task 0.5 phần còn lại, ≈7h)
Nhánh: `feat/m01/0-5/dev2/websites-ui`. Mockup: `websites.html`.
| Bước | Việc | Dùng lại / liên kết |
|---|---|---|
| 1 | Bảng: cột CMS (outline badge), trạng thái (`StatusPresenter`), đồng bộ gần nhất, lọc WordPress/Magento, banner khi có site `error` | Giữ cột hiện có |
| 2 | Slide-over "Chi tiết kết nối" (Infolist): URL, store scope, `capability_flags`, lần kiểm tra gần nhất | Dùng `last_synced_at` hiện có; **nhật ký 90 ngày chưa làm** (cần bảng PR-C) – hiện "Chưa có dữ liệu", không bịa |
| 3 | Action "Đổi credential" (modal, field password, mã hóa lại qua cast sẵn, ghi vào `encrypted_credentials`) | Không bao giờ nạp giá trị cũ ra form |
| 4 | Validate: HTTPS, chặn nội bộ bằng **`UrlSafetyService` của Dev1 (đã có)**, báo trùng `base_url`+`store_scope` | Tái dùng, không viết `SafeUrl` mới (khác plan cũ) |
| 5 | Action "Kiểm tra kết nối": gọi `PublisherManager`/connector nếu có `testConnection`; WordPress → "Chưa hỗ trợ (1.2)" | Phụ thuộc PR-D; trước đó chỉ Magento |
| 6 | Action "Thu hồi kết nối": modal lý do + đếm bài/publication `scheduled` ảnh hưởng; xóa credential; `status=revoked` | Hậu quả hiển thị trung thực: "sẽ báo lỗi" cho đến khi có `held` (PR-C) |
| 7 | Fixture dev 3 site (WP staging / WP giống prod / Magento staging), gắn nhãn "dữ liệu mẫu", chỉ ở `local` | Seeder do Dev1 (E6 mật khẩu mặc định) → đề xuất qua issue, không sửa |
Test: không lộ credential, writer bị chặn, URL nội bộ/metadata bị chặn, thu hồi xóa credential, xóa bị chặn.
Skill: `a11y-audit` thủ công cho modal + slide-over (focus trap, `Esc`), `visual-qa`.

### Đợt 3 — Bài viết và Duyệt bài (task 3.4, phần "Phải có", ≈16h, chia 4 PR)
Mockup: `article-review.html`, `article-workshop.html` (phần workshop **không** làm V1.0).
Nhánh con (cùng mã 3-4): 
| PR | Nhánh | Nội dung | Phụ thuộc |
|---|---|---|---|
| 3a | `feat/m03/3-4/dev2/article-versions` | `ArticleVersionResource` chỉ-đọc: HTML trong iframe sandbox, SEO meta, nguồn trích, token + `cost_vnd`, model/prompt_version, ảnh đại diện + alt, lịch sử bản | không (cột đã có) |
| 3b | `feat/m03/3-4/dev2/review-actions` | Action: Gửi duyệt · Duyệt · Từ chối (lý do bắt buộc) · Xếp lịch (chỉ khi `approved`). Hai nút **tách rời** (P3). Ghi `reviewed_by/at/notes` + **log qua `AuditRecorder`** (§6, gồm cờ tự duyệt). Policy theo vai trò | thống nhất nguồn trạng thái (§0 điểm 1); `approved_version_id` chờ PR-C – tạm dùng `review_status` của bản, ghi chú rõ |
| 3c | `feat/m03/3-4/dev2/review-diff` | `ParagraphDiff` + hiển thị thay đổi giữa 2 bản; làm nổi bật thay đổi con số | không |
| 3d | `feat/m03/3-4/dev2/review-layout` | Bố cục 2 cột (bài \| thông tin SEO + cảnh báo chất lượng + "AI đã tạo phần nào"), nhãn "AI đề xuất + nguồn" theo P1/P6 | 3a–3c |
Điều chỉnh với mockup: **bỏ "Duyệt và xếp lịch" gộp** (trái P3 của brief); "Duyệt nhanh hàng loạt" để V1.2.
`ArticleResource`: giữ `generate_ai`, thêm cột trạng thái chuẩn, lọc theo website/trạng thái, tab "Chờ duyệt".
Test: ma trận quyền (writer không duyệt được bài; approver không tự sửa nội dung?—chốt ở Q4), từ chối thiếu lý do bị chặn, không xếp lịch được khi chưa duyệt, iframe có `sandbox` và HTML đã sanitize, ca XSS (`<script>`, `onerror`) không chạy.
Skill: `design-studio` Tier 3 cho 3d; `design-critique` + `a11y-audit` thủ công trước khi mở PR 3d; `visual-qa` mỗi PR.

### Đợt 4 — Lịch đăng (task 3.4 phần g + liên quan 3.6, ≈8h)
Nhánh: `feat/m03/3-4/dev2/schedule-ui`. Mockup: `calendar.html`.
| Việc | Ghi chú |
|---|---|
| Bảng hàng đợi hôm nay + lỗi + "Cần đối soát" từ `Publication` | Có sẵn |
| Action **Đối soát ngay** gọi `ReconcilePublicationJob` (Dev1, đã có) | Chỉ khi `needs_reconcile`; không tạo bài mới (AGENTS §1.4) |
| Hiển thị giờ VN + UTC; xem trước lịch khi cấu hình | Dùng `scheduled_at_utc`/`timezone` |
| Lịch tuần (lưới Livewire tự viết) | Đổi ngày bằng hộp thoại; kéo thả để V1.2 |
| **Nút dừng khẩn cấp** | UI chỉ **gọi** cơ chế do 3.6 (Dev1) cung cấp. Trước 3.6: nút hiện "Chưa hỗ trợ", **không** giả lập |
| Hiển thị `held` | Chờ PR-C |
Test: không sửa tay được key/trạng thái; đối soát chỉ ở trạng thái đúng; writer không dừng khẩn cấp.

### Đợt 5 — Chiến dịch + Tổng quan (≈12h, phụ thuộc PR-C)
Nhánh: `feat/m03/3-4/dev2/campaigns-ui`, `feat/m03/3-4/dev2/dashboard`. Mockup: `campaigns.html`, `dashboard.html`.
- Chiến dịch: danh sách (tiến độ, bài tuần này, chi phí AI), form cấu hình (tổng bài, bài/ngày, ảnh/bài, giờ đăng + xem trước lịch VN/UTC). **Nếu PR-C chưa merge:** chỉ làm danh sách gom theo `topic_cluster` có nhãn "tạm"; phần form chờ.
- Tổng quan: 3 widget tối thiểu – bài chờ duyệt (+quá hạn), bài lỗi/chờ đối soát, giờ đăng kế tiếp. Không làm "chào Hà…" cá nhân hóa kiểu mockup trước khi có dữ liệu thật.
- Skill: `design-studio` Tier 3 + `design-critique` cho Tổng quan.
- **Chưa có chủ:** dashboard không nằm trong WBS → cần mã việc (§6 Q3).

### Đợt 6 — Chất lượng (task 3.5, 8h)
Nhánh: `feat/m03/3-5/dev2/quality`. Thẻ "Chất lượng" (P0/P1/P2, thông báo VN, "Xem lỗi") gắn vào màn duyệt của Đợt 3; chặn Duyệt khi còn P0 (cho override có lý do + log). Thêm luật "thay đổi số liệu so với bản đã duyệt phải có nguồn" (mockup đã minh họa). Test đỏ cho `HtmlSanitizer` (style/id/target/src tùy ý) như plan cũ.

### Đợt 7 — Discover: Từ khóa + Kế hoạch nội dung (2.1 + 2.2, 18h; V1.2)
Nhánh: `feat/m04/2-1/dev2/keywords`, `feat/m04/2-2/dev2/content-plan`. Mockup có `keywords.html`; **chưa có** `content-plan` → làm mockup ngắn trước (skill `ux-strategy` + `frontend-design`). Cần bảng `keywords`, `content_plans` (Dev1, PR-C2). Kéo thả: V1 đổi ngày bằng hộp thoại.

### Đợt 8 — Phân tích: Audit, Search Console, Đối thủ (1.3, 5.1, 5.4; V1.2–V2)
Chưa có mockup `seo-audit`, `search-console`, `competitors` → mockup trước mỗi task. Phụ thuộc crawler 1.1 (Dev1), OAuth GSC. Chi tiết lập khi tới lượt (tránh đặc tả sớm khi chưa có dữ liệu thật).

### Đợt 9 — Cài đặt còn lại: Chi phí AI, Thương hiệu/RBAC (6.4, 6.5)
`ai-usage` tổng hợp từ `article_versions.cost_vnd/tokens_used` (đã có) – cần chốt chủ sở hữu giao diện (Dev1 làm 6.5 nhưng UI là Filament). Brand/Department + khóa dữ liệu theo thương hiệu ở 6.4.

---

## 6. Quyết định đã chốt (06/11/2026) và câu hỏi còn mở

### Đã chốt
| # | Quyết định | Hệ quả vào kế hoạch |
|---|---|---|
| Q1 | Dev2 (người phụ trách chính giao diện) được sửa `AdminPanelProvider.php`, `resources/css|views/filament`, `public/css`; an toàn vì làm trên nhánh riêng. | Đợt 1b làm ngay. Vẫn **giữ ranh giới** của AGENTS.md: không chạm `app/Models`, `database/migrations`, `config/`, `app/Jobs`, `app/Console` – cần thì PR độc lập của Dev1. Ghi dòng "mở rộng allowlist theo xác nhận của chủ nhiệm UI" vào mô tả PR 1b để Dev1 thấy khi review. |
| Q4 | Approver **được** tự sửa rồi tự duyệt, **nhưng bắt buộc có log**. | Xem "Thiết kế log duyệt" ngay dưới. |
| Q5 | Giữ hai nút Duyệt / Xếp lịch riêng; bỏ hẳn nút Xóa website. | Đợt 1a (U4) và 3b làm đúng như vậy. |

### Thiết kế log duyệt (Q4)
Mỗi lần **Gửi duyệt · Duyệt · Từ chối · Xếp lịch · Override chặn P0 · Thu hồi website · Dừng khẩn cấp** ghi một bản ghi gồm: người thực hiện, vai trò, hành động, `article_id`, `article_version_id`, thời điểm UTC, lý do, và cờ `self_approved` (người duyệt trùng người sửa bản đó, hoặc trùng `articles.created_by`).
- **Lưu ở đâu:** bảng `audit_logs` (hoặc `activity_log`) là thay đổi `database/migrations` + `app/Models` → **PR độc lập của Dev1, gộp vào PR-C**. UI chỉ ghi qua một lớp `AuditRecorder` trong `app/Filament/Support/` để đổi nơi lưu không ảnh hưởng các action.
- **Trong lúc chờ PR-C:** `AuditRecorder` ghi log có cấu trúc qua `Log::info('audit', [...])` (kênh mặc định, không sửa `config/logging.php`) **và** `reviewed_by/reviewed_at/review_notes` có sẵn trên `ArticleVersion`. Mức này **chỉ là tạm** – không truy vấn được trên giao diện; PR ghi rõ "chưa có bảng audit".
- **Hiển thị:** tab "Lịch sử" ở màn duyệt, dòng có huy hiệu "Tự duyệt"; tự duyệt yêu cầu nhập ghi chú ngắn (không để trống). Quản lý lọc được các lượt tự duyệt.
- **Test:** mỗi action ghi đúng 1 log, đủ trường; tự duyệt không ghi chú bị từ chối; writer không ghi được log duyệt.

### Quy trình nhánh áp dụng (đọc lại GITHUB_WORKFLOW.md)
- Tên: `<loại>/<nhóm>/<mã-việc>/<người>/<slug>`; mã việc dùng gạch ngang (`0.5`→`0-5`), nhóm là Epic (`m01`…). Tất cả nhánh trong §5 đã theo đúng cú pháp này.
- Cắt từ `develop` **mới nhất**: máy local chưa có nhánh `develop`, nên dùng `git fetch && git checkout -b <tên> origin/develop` (hiện trùng `main` tại `9d39b90`). Không làm việc trên `main`.
- Commit theo Conventional Commits (`fix(filament): …`, `feat(filament): …`); PR vào `develop`, tiêu đề `[Mã] Tóm tắt`; mô tả đủ 4 mục (mục tiêu, file thay đổi, bằng chứng test, điểm chưa kiểm thử – ghi "Đã test Mock, chưa test Live CMS" khi đúng như vậy).
- Tác giả không tự duyệt PR của mình: Dev1 review; CI xanh mới merge; **Squash and Merge**, xóa nhánh sau merge. Không commit trực tiếp `main`/`develop`.
- Mỗi PR chỉ một mối quan tâm (đợt 1 đã tách 1a bảo mật / 1b giao diện) để một PR lỗi không kéo theo PR khác.
- Cập nhật `TASKS.md` kèm SHA commit sau mỗi task hoàn thành (theo AGENTS.md §4).

### Còn mở
| # | Hỏi ai | Câu hỏi | Chặn |
|---|---|---|---|
| Q2 | Dev1 | Lịch và hạn PR-A (WebP), PR-B (Policies), PR-C (migration lô A + `held`), PR-D (PublisherInterface/CmsError) | Đợt 2.5, 3b, 4, 5 |
| Q3 | Dev1 + quản lý | Mã việc/WBS cho Tổng quan và `ai-usage`; nhánh nào dùng cho phần UI không có mã (đề xuất tạm dùng `0-5`/`3-4` như trên) | Đợt 5, 9 |
| Q4′ | Bạn | Nguồn sự thật trạng thái bài (§0 điểm 1). Đề xuất: `Article.workflow_status` để điều hướng/lọc, `ArticleVersion.review_status` ghi quyết định từng bản – đồng ý không? | Đợt 3b |
| Q6 | Quản lý | Cần giao diện tiếng Anh không; số thương hiệu đồng thời | Đợt 9 |

---

## 7. Bảng nhãn trạng thái (nguồn duy nhất cho `StatusPresenter`)

| Giá trị | Nhãn | Màu (token) | Ghi chú |
|---|---|---|---|
| `planned` | Đã lên kế hoạch | gray | |
| `drafting` | Đang viết (AI) | info | |
| `needs_review` | Chờ duyệt | info | |
| `rejected` | Cần sửa | warn | mockup |
| `approved` | Đã duyệt | ok (nhạt) | chưa xếp lịch |
| `scheduled` | Đã xếp lịch | info | |
| `publishing` | Đang đăng | warn | |
| `published` | Đã đăng | ok | |
| `failed` | Lỗi | danger | |
| `needs_reconcile` | Chờ đối soát | danger | |
| `held` | Tạm giữ | gray | **chưa tồn tại** (cần PR-C) |
| Website: `active/pending/revoked/error` | Hoạt động / Chờ kiểm tra / Đã thu hồi / Lỗi kết nối | ok / gray / gray / warn | |

---

## 8. Định nghĩa hoàn thành cho mọi PR

Test đỏ→xanh (kể cả ca âm tính phân quyền) · không có credential/khóa trong HTML (kiểm bằng `assertDontSee`) · mọi hành động kiểm quyền ở server · chụp màn bằng trình duyệt tích hợp ở ≥2 viewport, sáng/tối, so với mockup · tương phản đo bằng `contrast-check.mjs` · Tab/focus/`Esc` thử tay trên modal & slide-over · nêu rõ skill/công cụ **đã chạy** và **chưa chạy** (axe, `/root/ai-design-studio` không truy cập được) · mức bằng chứng L1/L2/L3/L4 · PR nhỏ, Dev1 review, Squash and Merge, xóa nhánh.

## 9. Ước lượng

| Đợt | Giờ | Ghi chú |
|---|---|---|
| 0 | 2 | |
| 1 | 6 | trong 10h của 0.5 |
| 2 | 7 | → 0.5 tổng 13h (vượt 3h so với WBS 10h; lấy từ dự phòng) |
| 3 | 16 | WBS 3.4 là 10h; thực tế ≈ gấp rưỡi như plan cũ ước |
| 4 | 8 | |
| 5 | 12 | |
| 6 | 8 | đúng WBS 3.5 |
| 7–9 | theo WBS | mockup bổ sung ≈10h từ dự phòng |
