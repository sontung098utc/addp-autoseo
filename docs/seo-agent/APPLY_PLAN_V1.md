# PHƯƠNG ÁN ÁP DỤNG VÀO HỆ THỐNG HIỆN TẠI — BẢN RÀ SOÁT (CHƯA THỰC THI)

> v1.0 · 07/11/2026 · Người lập: trợ lý AI cho Dev2 · Cơ sở: `origin/develop` @ `7e9c2c0` (đã `git fetch`).
> **Mục đích:** biến định hướng "tự động hóa, người có cũng được" thành thay đổi **an toàn, từng bước, không mất giá trị đã xây**, đúng `AGENTS.md` và `GITHUB_WORKFLOW.md`.
> **Chưa sửa code, chưa tạo nhánh/PR, chưa sửa `TASKS.md`.** Chỉ thực thi sau khi bạn duyệt (§12).
> Kế thừa: `AI_AUTOMATION_DESIGN_ANALYSIS.md`, `AI_AUTOMATION_EXECUTION_PLAN.md`, `USABILITY_TEST_SCRIPT.md`, mẫu `mockups/`. Tài liệu này **thu hẹp** các kế hoạch đó thành bản áp dụng tối thiểu (theo kết luận "ít hơn thì dễ dùng hơn").

---

## 1. Cam kết bảo toàn (điều không được làm hỏng)
| # | Cam kết | Cách kiểm |
|---|---|---|
| C1 | **Không đổi hành vi nghiệp vụ** của duyệt bài, xếp lịch, xuất bản, đối soát, dừng khẩn cấp, quyền | Các test hiện có vẫn xanh; không sửa `PublishArticleJob`, `PublisherManager`, `ReconcilePublicationJob`, `EmergencyStopService`, `ReviewWorkflow` trong giai đoạn đầu |
| C2 | **Giữ nguyên URL/slug, route, tên model, cột DB** | Test điều hướng/`PanelSmokeTest`; không rename; không migration từ Dev2 |
| C3 | **Chỉ thêm (additive)**: lớp mới, trang mới, thuộc tính mới; sửa tại chỗ chỉ khi là nhãn/văn bản | Mô tả PR liệt kê "đã đổi / không đổi hành vi" |
| C4 | **Dữ liệu thật và demo không trộn**: giao diện mới đọc dữ liệu thật; dữ liệu mẫu chỉ ngoài production (`DemoMode`) | `DemoDataIsolationTest` + test mới cho từng trang |
| C5 | **Không nút giả / không số giả**: chưa nối thì khóa kèm lý do, thiếu dữ liệu thì "Chưa có dữ liệu" | Checklist PR |
| C6 | **Hoàn tác dễ**: mỗi PR nhỏ, squash, `git revert` là đủ (không có migration dữ liệu từ Dev2) | Ghi cách rollback trong PR |
| C7 | **Đúng phạm vi file** (`AGENTS.md` §3): Dev2 chỉ sửa `app/Filament/`, `resources/`, `tests/`, `docs/`, và phối hợp ở `app/Services/Content|Ai`, `resources/prompts|playbooks`; việc của Dev1 đi qua issue | Kiểm danh sách file khi review |

---

## 2. Rà soát hiện trạng (bằng chứng thật)
### 2.1 Git và quy trình
| Phát hiện | Bằng chứng | Mức |
|---|---|---|
| `main` = `develop` = `7e9c2c0`; Dev1 chưa đẩy thêm | `git rev-parse` | — |
| **Khối UI Dev2 (179 file, +16.607 dòng) vào `develop` bằng merge trực tiếp, không qua PR/review chéo** (commit `77fb0a9` bởi tài khoản Dev1); PR #5 và PR #8 đều **0 review** | `git log --first-parent`, `gh pr view` | **Cao** — trái `GITHUB_WORKFLOW.md` §2.4 và `AGENTS.md` §4 |
| `TASKS.md` đánh "Hoàn thành" 0.5, 1.2, 1.3, 2.1, 2.2, 3.4, 3.5 và ghi SHA merge; không ghi mức bằng chứng (staging/người thật) | `TASKS.md` | Trung bình — trái "Honest Evidence Contract" (4 mức) |
| **Không thấy lượt chạy CI nào** trên GitHub (`gh run list` rỗng; có thể do quyền, chưa xác minh) | `gh run list` | Cần xác minh |
| Nhánh làm việc hiện tại `docs/m01/0-5/dev2/ui-redesign-plan` đã được merge (PR #7) → nhánh cũ | `git log` | Thấp |
| Dọn dẹp: 52 nhánh local và 38 nhánh remote đã merge; 21 worktree; nhánh `tmp/ui-preview` (có commit "không push") | `git branch --merged`, `git worktree list` | Thấp (gây rối) |
| Chưa commit: 3 file doc trong `docs/plan/`, thư mục `docs/seo-agent/`, `README.md` (+2 dòng link), 2 file `bootstrap/cache/*` (chỉ đổi quyền file, 0 dòng) | `git status` | — |
| Issue #6 (PR-A…D) mở: **A (WebP), B (Policies) đã xong**; **C (campaigns…), D (PublisherInterface/CmsError) chưa**. Issue #9 mở, 0 phản hồi | `gh issue list` | — |

### 2.2 Test và môi trường
| Phát hiện | Bằng chứng |
|---|---|
| Chạy toàn bộ test trên **bản `develop` sạch** (worktree riêng, SQLite in-memory): **130 pass, 6 fail**, 343 cảnh báo | Chạy thật bằng image `addp-autoseo-app` |
| **6 fail đều là `imagewebp()` không tồn tại** — do **image Docker cục bộ cũ** (dựng 06/11 09:02) chưa có `libwebp`; `Dockerfile` trên `develop` **đã sửa** (`63ef160`). GD WebP trong image = `false` | `gd_info()` |
| 343 cảnh báo = PHPUnit "metadata trong doc-comment" (sẽ không hỗ trợ ở PHPUnit 12); không phải lỗi | Kết quả test |
| CI dùng MariaDB + Redis và `--stop-on-failure`; test cục bộ dùng SQLite — **hai môi trường khác nhau**, chưa biết CI xanh | `phpunit.xml`, `ci.yml` |
→ Suy ra: baseline "đúng" sau khi dựng lại image là **136/136**, nhưng **chưa được chứng minh** cho đến khi chạy.

### 2.3 Giao diện hiện tại (từ bản xem thử UI, cùng mã với `develop`)
| Phát hiện | Mức |
|---|---|
| Tiêu đề trang lệch với menu: menu "Duyệt bài" ↔ tiêu đề "Phiên bản bài"; "Website kết nối" ↔ "Danh sách Website"; "Chiến dịch & Bài viết" ↔ "Danh sách Bài viết" (nhãn do `pluralModelLabel`) | Trung bình, sửa dễ |
| Widget Tổng quan hiển thị trống ở lần tải đầu (chưa xác định nguyên nhân: tải lười hay lỗi) | Cần xác minh |
| Dữ liệu mẫu có tiền tố `[Mẫu TQ]`, `[Mẫu AI]`, `[Mẫu]` — chỉ ngoài production (`DemoMode`) nhưng lẫn vào danh sách thật khi xem thử | Thấp–trung bình |
| 14 mục menu; cấu trúc 5 nhóm | Giữ, chỉ gọn dần |

### 2.4 Nền tảng có thể tận dụng ngay (không cần Dev1)
`ScheduleExplainer` (giải thích lịch), `ContentQualityChecker` + 9 rule + `QualityThresholds`, `StatusPresenter`, `DemoMode/DemoStore`, `AuditRecorder`, `ParagraphDiff`, dữ liệu crawler thật (`seo_audits`, `crawled_urls`), `ArticleVersion` (provider/model/token/`cost_vnd`), `EmergencyStopService`, `Policies` 3 vai trò.

---

## 3. Sổ tồn đọng (làm sạch trước khi làm tiếp)
| ID | Tồn đọng | Chủ | Hành động | Cổng |
|---|---|---|---|---|
| T1 | Khối UI chưa qua review chéo | Dev1 (review) + Dev2 | **Hậu kiểm** theo checklist bảo mật (credential bị khóa, XSS/`iframe sandbox`, Policies, `ScanGuard`/SSRF, `DemoMode` ở production, không lộ secret) → danh sách lỗi | **G0** |
| T2 | Image Docker cục bộ cũ → 6 test đỏ | Dev2 (cục bộ) | Dựng lại image; chạy lại; ghi baseline 136/136 | **G0** |
| T3 | CI chưa rõ có chạy | Dev1 | Xác nhận Actions bật; một lượt CI xanh trên `develop` | **G0** |
| T4 | Tiêu đề trang lệch menu; widget trống; nhãn mẫu | Dev2 | `AG-H05` | **G0** |
| T5 | `TASKS.md` thiếu mức bằng chứng | Dev2 đề xuất, Dev1 duyệt | `AG-H09` | G0 (không chặn code) |
| T6 | Tài liệu `seo-agent/` chưa vào repo; README sửa dở | Dev2 | `AG-H01` | **G0** |
| T7 | Dọn nhánh/worktree/preview | Dev2 (cần xác nhận của bạn) | `AG-H06` | G0 (nên) |
| T8 | Issue #6 còn PR-C, PR-D; #9 chưa trả lời | Dev1 | Theo dõi; cập nhật issue #6 | Chặn Giai đoạn 2, **không chặn** G0–G1 |
| T9 | Trạng thái dừng khẩn cấp lưu trong Cache | Dev1 | Issue #9 (`AG-B08`) | Chặn bật tự đăng, **không chặn** G0–G1 |
| T10 | 343 cảnh báo PHPUnit | Dev2 | `AG-H08` | Không chặn |
| T11 | `bootstrap/cache/*` đổi quyền file | Dev2 | Không commit; đặt `core.fileMode=false` cục bộ hoặc hoàn tác | G0 |

---

## 4. Phương án áp dụng: từ định hướng xuống hệ thống hiện tại
Định hướng đã chốt: **ít hơn thì dễ dùng hơn**; tự động hóa là mặc định nhưng xuất bản luôn an toàn.
Phân loại: **GIỮ** nguyên · **MỞ RỘNG** (thêm, không đổi hành vi) · **THÊM** mới · **HOÃN** (làm khi có bằng chứng).

| Khu vực hiện có | Quyết định | Cách làm cụ thể |
|---|---|---|
| Duyệt bài (`ArticleVersionResource`), kiểm chất lượng, duyệt/từ chối/xếp lịch | **GIỮ**, **MỞ RỘNG** sau | Giai đoạn 1: chỉ đổi nhãn tiêu đề; Giai đoạn 3: sửa theo đoạn |
| Lịch tuần / Lịch xuất bản / `ScheduleExplainer` | **GIỮ**, **MỞ RỘNG** | Dùng lại `ScheduleExplainer` làm nền cho "Vì sao chưa đăng?" |
| Dừng khẩn cấp (`EmergencyStopService`, nút ở `ListPublications`) | **GIỮ**, **MỞ RỘNG** | Thêm banner toàn cục (chỉ đọc `getStopInfo`); không sửa dịch vụ |
| Audit / Từ khóa / Kế hoạch / Đối thủ / Search Console (đang dữ liệu mẫu) | **GIỮ**; Audit **MỞ RỘNG** | Audit thêm nguồn `DbSeoAuditSource` đọc dữ liệu crawler thật; các trang còn lại giữ nguyên, ghi nhãn trung thực |
| `ContentQualityChecker` + rule + `QualityThresholds` | **GIỮ**, **MỞ RỘNG** | Thêm metadata (nguồn, lý do, `reviewed_at`) và rule mới theo xác minh; giữ mã luật |
| `ContentPipelineService`, `AiProviderInterface`, Gemini/Mock | **GIỮ** | Không đụng ở G0–G1; router/vòng sửa ở Giai đoạn 2–3 qua Dev1 |
| Menu 14 mục / 5 nhóm | **MỞ RỘNG nhẹ** | Gọn bằng nhóm "Nâng cao" theo vai trò; **không** gộp trang bằng tab, **không** đổi URL |
| Trang "Cách hoạt động", "Bản tin", "Vì sao chưa đăng?", Banner dừng | **THÊM** | Dựa dữ liệu thật có sẵn (§2.4), không cần bảng mới |
| Ba chế độ chiến dịch, hướng dẫn & brief, cài đặt 5 bước | **HOÃN** → Giai đoạn 3 | Cần bảng/lưu trữ của Dev1 (`campaigns`, `trust_policies`) |
| Vòng tự sửa, tự phục hồi, router đa model, orchestrator, ngân sách | **HOÃN** → Giai đoạn 2–3 | Việc của Dev1 + Dev2 theo kế hoạch `AG-*` |
| Pipeline map, Vai trò & Kỹ năng (UI), Tích hợp AI (UI đầy đủ), Chỉ số, Nhập báo cáo, trang gộp tab | **HOÃN** → Giai đoạn 4 | Chỉ làm khi người dùng thật đòi (kết quả `AG-F05`) |

---

## 5. Các giai đoạn và cổng
```
G-0  DỌN SẠCH & BASELINE   ──(cổng G0)──►  G-1  GIAO DIỆN NỀN TRÊN DỮ LIỆU THẬT  ──(cổng G1)──►
G-2  NỀN TỰ ĐỘNG (Dev1 dẫn)  ──(cổng G2)──►  G-3  ĐƯỜNG MỎNG WORDPRESS (chỉ nháp)  ──(cổng G3)──►  G-4  MỞ RỘNG THEO BẰNG CHỨNG
```
**Quy tắc cổng:** không bắt đầu giai đoạn sau khi cổng chưa đạt. Mỗi cổng có checklist và **bằng chứng thật** (log test, ảnh chụp, link PR).

### Cổng G0 — "Sạch"
- [ ] Image dựng lại; **136/136 test xanh** (log đính kèm). Cảnh báo ghi lại, không chặn.
- [ ] CI GitHub chạy và xanh trên `develop` (hoặc có ghi nhận rõ lý do chưa).
- [ ] Hậu kiểm (T1) xong, mọi lỗi mức cao đã sửa hoặc có issue.
- [ ] Tiêu đề trang khớp menu; widget Tổng quan đã xác minh; nhãn dữ liệu mẫu rõ.
- [ ] Tài liệu `seo-agent/` vào `develop` qua PR có review chéo.
- [ ] Nhánh/worktree thừa đã dọn (sau khi bạn xác nhận).
- [ ] `TASKS.md` có mục M09 (đề xuất) và ghi mức bằng chứng cho các task đã xong.

### Cổng G1 — "Nền giao diện đạt"
- [ ] Toàn bộ test xanh; test mới cho từng trang/dịch vụ mới (gồm ca âm tính theo vai trò).
- [ ] Kiểm hình ảnh 375/768/1440 sáng+tối bằng trình duyệt; ghi rõ công cụ nào chạy thật.
- [ ] **`AG-F05`**: thử với 5 người đã chạy; nhiệm vụ < 80% thành công đã sửa.
- [ ] Mọi PR có review chéo của Dev1 (không còn merge thẳng).
- [ ] Không thay đổi hành vi nghiệp vụ (C1) — chứng minh bằng test cũ không bị sửa.

### Cổng G2 — "Nền tự động có an toàn" (Dev1 dẫn)
- [ ] Migration `agent_*`, `ai_integrations`, `ai_routing_rules` đã merge; ghi run/vết quyết định chạy được.
- [ ] `AiModelRouter` **không còn fallback im lặng sang Mock** (nghiệm thu "Mock lọt vào bài = 0").
- [ ] Ngân sách + ngắt mạch; dừng khẩn cấp bền (`AG-B08`).

### Cổng G3 — "Đường mỏng đạt số liệu"
- [ ] Chạy N lần (ví dụ 20) trên một blog WordPress ở chế độ **chỉ đẩy nháp**: tỉ lệ hoàn tất, tỉ lệ tự sửa, chi phí/bài, **0 bài lên live**, **0 nội dung giả lập** (ngưỡng do bạn chốt).
- [ ] Văn bản chính sách tự đăng **chưa** có ⇒ **không** mở chế độ Tự chủ.

---

## 6. Danh mục task theo giai đoạn (chuẩn git workflow)
Quy ước nhánh `GITHUB_WORKFLOW.md` §1: `<loại>/<nhóm>/<mã-việc>/<dev>/<mô-tả>`. Nhóm mới **`m09`**; mã `ag-*`. **Điều kiện:** mã `AG-*` phải có trong `TASKS.md` (qua PR `AG-H01`) trước khi mở nhánh nào khác. Giờ là ước lượng thô, chưa Dev nào estimate.

### Giai đoạn 0 — Dọn sạch & baseline (song song)
| Mã | Tên | Chủ/Review | Nhánh | File/phạm vi | Xong khi | Giờ |
|---|---|---|---|---|---|---:|
| **AG-H01** | Đưa tài liệu M09 vào repo + link README + đề xuất mục M09 trong `TASKS.md` | Dev2 / Dev1 | `docs/m09/ag-h01/dev2/m09-docs` | `docs/seo-agent/**`, `README.md`, `TASKS.md` | PR được Dev1 duyệt; không có secret; **không** commit `bootstrap/cache/*` | 3 |
| **AG-H02** | Dựng lại image Docker (WebP) + chạy toàn bộ test + báo cáo baseline | Dev2 / Dev1 | (cục bộ; báo cáo vào PR H01) | `docs/seo-agent/reports/**` | 136/136 hoặc danh sách lỗi thật kèm nguyên nhân | 1 |
| **AG-H03** | Xác nhận và bật CI (Actions); một lượt xanh trên `develop` | Dev1 / Dev2 | `chore/m09/ag-h03/dev1/ci-verify` | `.github/workflows/**` | Có link run xanh | 2 |
| **AG-H04** | **Hậu kiểm review chéo** khối UI đã merge (bảo mật + hành vi) | Dev1 (rà) + Dev2 (tự kiểm) | `docs/m09/ag-h04/dev1/post-merge-review` | báo cáo + issue | Danh sách lỗi có mức độ; lỗi cao đã có PR sửa | 10 |
| **AG-H05** | **Sửa nợ UX nhỏ**: tiêu đề khớp menu; xác minh và sửa widget Tổng quan trống; nhãn dữ liệu mẫu rõ; trạng thái rỗng | Dev2 / Dev1 | `fix/m09/ag-h05/dev2/ux-debt` | `app/Filament/Resources/*Resource.php` (nhãn), `Widgets/**`, `tests/Feature/Filament/**` | Menu↔tiêu đề khớp; smoke test mọi trang; ảnh trước/sau | 6 |
| **AG-H06** | **Dọn Git** (nhánh local/remote đã merge, worktree, `tmp/ui-preview`, container preview) — **chỉ sau khi bạn xác nhận danh sách** | Dev2 | (không PR) | — | Danh sách xóa được bạn duyệt; xóa xong; không mất commit chưa merge | 2 |
| **AG-H07** | Cập nhật issue #6 (A, B đã xong; C, D còn) và theo dõi #9 | Dev2 | (không PR) | issue | Issue phản ánh đúng thực tế | 0,5 |
| **AG-H09** | Ghi **mức bằng chứng** cho các task đã "Hoàn thành" trong `TASKS.md` (merge / test mock / staging / người thật) | Dev2 / Dev1 | `docs/m09/ag-h09/dev2/task-evidence` | `TASKS.md` | Không task nào "Hoàn thành" mà thiếu mức bằng chứng | 2 |
| **AG-F04** | Chọn nguồn dữ liệu SEO cho Việt Nam (GSC + từ khóa/SERP), chi phí, dữ liệu gửi ra ngoài | Dev2 / Dev1 + bạn | `docs/m09/ag-f04/dev2/seo-data-source` | `docs/seo-agent/data-sources.md` | Quyết định *được/không* từng nguồn | 6 |
| **AG-K01** | Danh mục học từ claude-seo + **xác minh quy tắc L1–L9 với tài liệu Google** | Dev2 / Dev1 | `docs/m09/ag-k01/dev2/knowledge-catalog` | `docs/seo-agent/knowledge/**` | Mỗi quy tắc: đã xác minh/chưa/bác bỏ + nguồn | 6 |
| **AG-F05** | **Thử nghiệm khả dụng 5 người** trên mẫu (không cần code) | Dev2 + bạn | `docs/m09/ag-f05/dev2/usability-results` | `docs/seo-agent/reports/**` | Báo cáo thật; sửa mẫu rồi thử lại 3 người | 6 |
| **Dev1 song song:** `AG-B00` (job quét audit), `AG-B08` (bền hóa dừng khẩn cấp), `AG-F03` (ADR orchestrator/n8n/giấy phép) — theo issue #9 | Dev1 | `feat|fix|docs/m09/ag-*/dev1/…` | | | | 16 |
| | | | | | **Tổng Dev2** (gồm ≈ 4h của H04) | **≈ 37** |

### Giai đoạn 1 — Giao diện nền trên dữ liệu thật có sẵn (Dev2; không bảng mới)
| Mã | Tên | Phụ thuộc | Nhánh | File | Kiểm thử | Giờ |
|---|---|---|---|---|---|---:|
| **AG-U00** | Gọn menu: nhóm **"Nâng cao"** theo vai trò (admin thấy đủ; trưởng nhóm duyệt/biên tập thấy phần việc); **giữ nguyên URL** | G0, H05 | `feat/m09/ag-u00/dev2/nav-advanced` | `AdminPanelProvider.php` **(chủ duy nhất)**, thuộc tính `navigationGroup` | Smoke + test quyền (có ca âm tính) | 5 |
| **AG-U01** | Nhãn trạng thái & chip AI/Nhập/Mẫu (mở rộng `StatusPresenter`) | G0 | `feat/m09/ag-u01/dev2/status-chips` | `Support/StatusPresenter.php`, `components/studio/**` | Mở rộng `StatusPresenterTest`; kiểm tương phản | 6 |
| **AG-U11a** | **Banner dừng xuất bản toàn cục** (chỉ đọc `EmergencyStopService::getStopInfo`; không sửa dịch vụ) | G0 | `feat/m09/ag-u11a/dev2/stop-banner` | `AdminPanelProvider` hook (qua U00), view | Test: hiện/ẩn đúng; không đổi hành vi dừng | 3 |
| **AG-U02** | **Cách hoạt động** + `FeatureRegistry` (`Đang hoạt động` bắt buộc có bằng chứng) | U01 | `feat/m09/ag-u02/dev2/how-it-works` | `Pages/HowItWorksPage.php`, `Support/Agent/Features/*.php` | Test chặn "hoạt động" thiếu bằng chứng | 8 |
| **AG-U23** | **"Vì sao chưa đăng?"** (v1): giải thích bằng **luật thật** (dừng khẩn cấp, website không hoạt động, chưa duyệt, lỗi chất lượng P0, chưa tới giờ, trạng thái) — mở rộng `ScheduleExplainer` | U01 | `feat/m09/ag-u23/dev2/why-not-published` | `Support/Explain/**`, `Support/Schedule/ScheduleExplainer.php`, view | Test từng luật; **không** đổi dữ liệu | 10 |
| **AG-U19** | **Bản tin hằng ngày** (v1) từ dữ liệu thật: bài mới/đã duyệt/đã lên lịch/lỗi/quá hạn, chi phí AI (`cost_vnd`), kết quả quét; **trạng thái trống** và **ngày nhiều việc** (gom ≤ 4 nhóm); phần "AI đã tự xử lý" **ẩn** tới khi có nhật ký run | U01, U23 | `feat/m09/ag-u19/dev2/daily-digest` | `Pages/DigestPage.php`, `Support/Dashboard/**` | Test số liệu khớp DB; test 3 trạng thái; demo cô lập | 12 |
| **AG-K04** | Bảng ánh xạ **mã lỗi crawler ↔ `IssueCatalog` P0/P1/P2** và `score_method` | — | `feat/m09/ag-k04/dev2/audit-mapping` | `Support/SeoAudit/IssueCatalog.php`, `resources/playbooks/audit/**` | Cùng URL cho cùng kết luận ở UI và crawler | 6 |
| **AG-U05** | **Audit thật**: `DbSeoAuditSource` đọc `seo_audits`/`crawled_urls`; **giữ** bảng nhóm lỗi/slide-over; chip nguồn; nút *Quét lại* khóa tới khi có `AG-B00` | K04, B00 | `feat/m09/ag-u05/dev2/audit-db-source` | `Pages/AuditPage.php`, `Support/SeoAudit/DbSeoAuditSource.php` | Test nguồn thật + nguồn mẫu; production không hiện số mẫu | 12 |
| **AG-K03** | **Rubric có metadata** (nguồn/lý do/`reviewed_at`) trên `QualityThresholds`; giữ mã luật và `ContentQualityChecker`; thêm rule mới **chỉ sau khi K01 xác minh** | K01 | `feat/m09/ag-k03/dev2/rubric-metadata` | `Services/Content/Quality/**` | Toàn bộ test chất lượng hiện có giữ nguyên xanh | 12 |
| **AG-U26** | **Ma trận quyền theo vai trò** cho mọi hành động mới (dùng `Policies` có sẵn) | U00 | `feat/m09/ag-u26/dev2/role-matrix` | `app/Filament/**` | Test ma trận có ca âm tính | 6 |
| | | | | **Tổng Dev2** | | **≈ 80** |

### Giai đoạn 2 — Nền tự động (Dev1 dẫn; Dev2 chuẩn bị song song)
- **Dev1:** `AG-B01/B02` (migration), `AG-B03` (RunRecorder), `AG-B06` (`AiModelRouter`, bỏ Mock im lặng), `AG-B09` (ngân sách, ngắt mạch), `AG-B10` (orchestrator), `AG-A02` (tự phục hồi), `AG-B11` (thông báo), `AG-B12` (vết quyết định). ≈ 112h (kế hoạch `AI_AUTOMATION_EXECUTION_PLAN.md` §4).
- **Dev2 (không chờ DB):** `AG-K02` định dạng Playbook, `AG-K05` brief, `AG-K07` biên tập/QA, `AG-K11` hồ sơ doanh nghiệp, `AG-K12` hạng rủi ro + nguồn duyệt, `AG-K13` từ cấm kiểm bằng luật, `AG-K09` bộ đánh giá VN.

### Giai đoạn 3 — Đường mỏng WordPress, chỉ đẩy nháp
`AG-A01` vòng viết–chấm–sửa · `AG-A03` tìm trong nguồn duyệt · `AG-U22` ba chế độ · `AG-U24` cài đặt 5 bước · `AG-U25` hướng dẫn & brief · `AG-U09` tích hợp AI · `AG-A05a` chính sách tin cậy T1 · `AG-U10a/b/c` sửa theo đoạn · `AG-U15` nối seam sang DB.

### Giai đoạn 4 — Mở rộng theo bằng chứng (HOÃN, chưa lập lịch)
Pipeline map · Vai trò & Kỹ năng (UI) · Chỉ số · Nhập báo cáo · gộp trang bằng tab · mức tự đăng (T2) + kiểm mẫu + gỡ bài · Magento nháp/gỡ.

---

## 7. Làn song song và chống xung đột
**Giai đoạn 0:** 4 làn Dev2 không đụng file nhau: (a) H01+H09+F04+K01 (chỉ `docs/`, `TASKS.md` — H01 và H09 cùng sửa `TASKS.md` nên **H09 làm sau H01**), (b) H05 (nhãn/widget), (c) H02/H06/H07 (cục bộ), (d) F05 (không code). Dev1: H03, H04, B00, B08, F03.
**Giai đoạn 1:** 4 làn: {U00 → U26, U11a} · {U01 → U02, U23, U19} · {K04 → U05} · {K01 → K03}.
| File dùng chung | Chủ duy nhất |
|---|---|
| `AdminPanelProvider.php` | **AG-U00** (U11a xin móc hook qua U00) |
| `StatusPresenter.php`, `components/studio/**` | **AG-U01** |
| `Resources/*Resource.php` (nhãn) | **H05 trước, rồi U00** (cùng file khác dòng — tránh xung đột bằng thứ tự merge) |
| `Support/Schedule/ScheduleExplainer.php` | **AG-U23** |
| `IssueCatalog.php` | **AG-K04** |
| `app/Services/Content/Quality/**` | **AG-K03** |
| `TASKS.md` | **H01 rồi H09** |
Hệ quả: nếu hai task cần cùng một file, **làm tuần tự** và ghi vào phụ thuộc, không cố song song.

---

## 8. Quy trình Git & PR cho từng task (đúng `GITHUB_WORKFLOW.md`)
```bash
git fetch origin
git worktree add ../wt-ag-h05 -b fix/m09/ag-h05/dev2/ux-debt origin/develop   # cắt từ develop mới nhất
cd ../wt-ag-h05  # làm việc tách biệt, không động nhánh khác
# ... sửa, test ...
git commit -m "fix(filament): tiêu đề trang khớp tên menu"   # Conventional Commits, tiếng Việt
git push -u origin fix/m09/ag-h05/dev2/ux-debt
gh pr create --base develop --title "[AG-H05] Sửa nợ UX: tiêu đề khớp menu, widget Tổng quan" --body-file pr.md
```
- **Không** merge thẳng vào `develop`/`main`; **Squash and Merge** sau khi Dev1 review và CI xanh; xóa nhánh sau merge.
- Tác giả PR **không tự duyệt**; Dev1 bắt buộc review (bảo mật, SSRF/XSS, lộ secret) và đọc log CI.
- **Mẫu mô tả PR:** *Mã task & mục tiêu* · *File thay đổi (trong allowlist)* · *Đã đổi / KHÔNG đổi hành vi* · *Bằng chứng test (log, ảnh trước/sau)* · *Chưa kiểm thử/cảnh báo* (ví dụ "đã test mock, chưa test CMS thật") · *Cách rollback* (`git revert <sha>`) · *Mức bằng chứng 4 tầng*.
- PR ≤ ~250 dòng logic; một PR một việc.
- Nhánh vẫn đang `docs/m01/0-5/dev2/ui-redesign-plan` (cũ) → **không** dùng; tạo nhánh mới từ `develop`.

---

## 9. Rủi ro khi áp dụng và cách giảm
| Rủi ro | Giảm thiểu |
|---|---|
| Làm hỏng luồng duyệt/đăng đang chạy | C1, C2, C3; không sửa dịch vụ lõi G0–G1; test cũ phải giữ xanh; PR nhỏ, rollback bằng `git revert` |
| Số liệu giả trên màn mới | C4, C5; trang mới chỉ đọc bảng thật; thiếu dữ liệu thì "Chưa có dữ liệu" |
| Review chéo không xảy ra tiếp | G1 bắt buộc review; hậu kiểm H04; không merge thẳng |
| Dev1 bận 4.1/4.2 làm chậm G2 | G0–G1 không phụ thuộc Dev1 (ngoại trừ review, H03, H04); G2 chỉ bắt đầu khi Dev1 xác nhận lịch |
| Cảnh báo/CI cục bộ ≠ CI GitHub | H02 (cục bộ) + H03 (CI thật); ghi rõ mức bằng chứng |
| Xung đột file khi song song | §7: một chủ mỗi file dùng chung; tuần tự khi cần |
| Mở rộng phạm vi ngoài WBS | Mã `AG-*` riêng, cần duyệt; không cộng vào 432h; không đổi trạng thái WBS gốc |
| Tự động đăng nội dung giả lập | Không bật tự đăng ở G0–G3; `AG-B06` bỏ Mock im lặng là điều kiện G2 |
| Mất công việc khi dọn Git | H06 chỉ xóa sau khi liệt kê và bạn duyệt; kiểm "không có commit chưa merge" |

---

## 10. Việc **chưa** biết (cần xác minh, không suy đoán)
1. CI GitHub có chạy không (chưa xem được lượt chạy).
2. Nguyên nhân widget Tổng quan trống (tải lười hay lỗi) — `AG-H05` xác minh.
3. Hành vi thật trên staging/CMS thật của 1.2, 1.4, 3.6 — chưa có bằng chứng staging.
4. WordPress/Magento connector có gỡ bài được không — chỉ cần cho T2, ngoài G0–G3.
5. Chất lượng AI tiếng Việt, hiệu quả vòng tự sửa — chỉ biết ở G3.
6. Quy tắc SEO của claude-seo có đúng không — `AG-K01`.

---

## 11. Những gì tôi đã làm trong lúc rà soát (để bạn biết dấu vết)
- `git fetch --all --prune` (không merge vào nhánh nào).
- Tạo **một worktree tạm** ở thư mục scratchpad từ `origin/develop` để chạy test (chỉ đọc mã), và chạy `docker run --rm` với image hiện có; **không** sửa container đang chạy, **không** đụng DB (test dùng SQLite trong bộ nhớ).
- Đọc `gh issue/pr/run`; **không** tạo/sửa issue hay PR nào trong lần này.
- Máy chủ xem thử tĩnh cổng 8777 (phục vụ mẫu) vẫn có thể đang chạy.

## 12. Cần bạn duyệt trước khi thực thi
1. Phương án thu hẹp (§4) và **5 giai đoạn có cổng** (§5); chỉ cam kết **G0–G1**.
2. Danh mục task Giai đoạn 0–1 (§6) và nhóm nhánh `m09`; cho phép mở PR `AG-H01` đưa tài liệu + mục M09 vào `TASKS.md`.
3. Cho phép **dựng lại image Docker cục bộ** (`AG-H02`) — thay đổi môi trường cục bộ của bạn.
4. Cho phép **dọn Git** (`AG-H06`) theo danh sách tôi sẽ liệt kê (cần bạn xác nhận từng nhóm; xóa nhánh remote là hành động công khai).
5. Gửi Dev1: `AG-H03` (CI), `AG-H04` (hậu kiểm review), `AG-F03` (ADR), nhắc `B00/B08` (issue #9), cập nhật issue #6.
6. Xác nhận ưu tiên **chạy `AG-F05`** (thử người dùng) song song với G0.
