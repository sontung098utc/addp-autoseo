# ĐỀ XUẤT THIẾT KẾ GIAO DIỆN DEV2 — "NGƯỜI ĐIỀU KHIỂN, AI CỘNG TÁC"

> Phiên bản 0.1 (bản nháp để duyệt) — 06/11/2026 · Vai trò: Senior Product Designer · Phạm vi: **chỉ phân tích và đề xuất**. Chưa sửa code, chưa đổi giao diện, chưa tạo nhánh/PR. Không triển khai cho đến khi được duyệt.
> Tài liệu liên quan: `../design-system/DESIGN_BRIEF.md`, `AUTO_SEO_AGENT_ARCHITECTURE.md`, `USER_EXPERIENCE_ROADMAP.md`, `docs/plan/DEV2_UI_REDESIGN_PLAN.md`.

---

## 0. Giới hạn bằng chứng của bản đánh giá này (đọc trước)

| Hạng mục | Đã làm | **Chưa làm / hạn chế** |
|---|---|---|
| Git | Đọc `git status`, danh sách worktree, so sánh nhánh với `origin/develop` | Không fetch mới, không kiểm tra PR đang mở trên GitHub |
| UI hiện tại | Đăng nhập bản xem thử cục bộ (`127.0.0.1:8101`, nhánh `tmp/ui-preview`) bằng tài khoản seed, mở 8 màn hình ở 1440px | **Chưa** kiểm tra mobile/tablet, dark mode, bàn phím, axe; **chưa** bấm thử từng action; Dashboard tải widget chậm nên chỉ thấy một phần |
| Design System | Đọc `DESIGN_BRIEF.md`, tóm tắt tokens/components/layout, trích nội dung chữ của 3 mockup (`article-workshop`, `dashboard`, `campaigns`) | Chưa đối chiếu từng pixel/từng mockup còn lại (`keywords`, `content-plan`, `seo-audit`, `search-console`, `competitors`, `calendar`, `websites`, `ai-usage`, `article-review`) |
| Bộ skill `claude-seo` | Đọc README công khai của `AgriciDaniel/claude-seo` (qua tìm kiếm + 1 lần fetch) | **Không cài trên máy này** (`~/.claude/skills` chỉ có 9 skill UI). Chưa đọc `docs/ARCHITECTURE.md`, `references/`, chưa chạy thử. Số lượng sub-skill/agent giữa các nguồn không khớp (19–26 skill, 12–19 agent) → coi là **xấp xỉ** |

Mọi nhận định "đang hoạt động" dưới đây nghĩa là *nhìn thấy trên bản xem thử*, **không** phải đã nghiệm thu staging/UAT (theo `AGENTS.md` §1.2).

---

## 1. Kiểm tra Git và công việc đang chạy (tránh xung đột)

### 1.1 Trạng thái hiện tại
- Nhánh làm việc: `docs/m01/0-5/dev2/ui-redesign-plan` (commit `5116748`). Chưa commit: `README.md`, `bootstrap/cache/*.php`, `docs/plan/ISSUE_PR_C_FOR_DEV1.md`, `docs/plan/PR_DESCRIPTIONS_DEV2.md`, thư mục `docs/seo-agent/` (gồm file này).
- `origin/develop` = `origin/main` = `9d39b90`. **Trên `develop` chỉ có Filament nền tảng** (4 Resource cơ bản, chưa có Dashboard, Duyệt bài, Lịch, SEO).

> **⚠ Cập nhật 07/11/2026 (đã lỗi thời một phần):** sau `git fetch`, `origin/develop` @ `7e9c2c0` **đã chứa** toàn bộ chuỗi nhánh UI Dev2 (không còn nhánh nào có commit ngoài `develop`), cộng crawler 1.1, autopublish + dừng khẩn cấp 3.6, Policies 3 vai trò. Các câu dưới đây về "`develop` chỉ có Filament nền tảng" và "UI nằm ngoài `develop`" là **tình trạng lúc 06/11**. Ở §12: S24 nay kiểm bằng Policy thật; S29 (dừng khẩn cấp) **đã có backend** (`EmergencyStopService`, lưu trong Cache). Xem `AI_AUTOMATION_EXECUTION_PLAN.md` §0.

### 1.2 Phát hiện quan trọng: "UI hiện tại" nằm ngoài `develop`
- Giao diện bạn đang xem ở `:8101` là nhánh cục bộ **`tmp/ui-preview`** = hợp nhất của ~30 nhánh Dev2: **199 file, +18.230 dòng** so với `develop`, chưa PR/merge. Nhánh này ghi rõ "chỉ nhánh xem thử cục bộ, không push".
- Có ~25 worktree Dev2 song song (`feat/m03/3-4/*`, `feat/m04/*`, `feat/m07/*`, `feat/m08/6-5/*`, `fix/*`, `test/*`), mỗi nhánh 1–101 commit trước `develop`. Nhánh dài nhất: `table-controls` (101), `trim-notes` (100), `ui-kit` (93).
- **Hệ quả cho đề xuất này:** mọi thứ gợi ý dưới đây phải xây trên chuỗi nhánh này, không phải trên `develop`. Nếu bắt đầu làm khi chuỗi chưa được merge theo thứ tự, sẽ xung đột nặng ở `app/Filament/Pages/*`, `resources/views/filament/pages/*`, `AdminPanelProvider.php`.

### 1.3 Điểm dễ xung đột (đã xác định, chưa chắc đã xảy ra)
| Vùng | Vì sao | Người giữ |
|---|---|---|
| `app/Providers/Filament/AdminPanelProvider.php` (nhóm menu, render hook, asset) | Mọi đề xuất điều hướng/khu AI đều chạm file này; plan cũ ghi chưa rõ có trong allowlist | Dev2, cần Dev1 xác nhận (Q1 của plan cũ) |
| `app/Filament/Support/Demo/*` | Mọi màn SEO dùng chung `DemoMode` | Dev2 |
| `database/migrations/`, `app/Models/` | Run/Task/Knowledge của kiến trúc agent cần bảng mới | **Dev1 (không được tự tạo)** — xem `ISSUE_PR_C_FOR_DEV1.md` |
| `app/Jobs/`, `app/Console/`, queue | Chạy workflow nền | Dev1 |
| `app/Services/Ai/`, `resources/prompts/` | Phối hợp; đề xuất agent chạm vào | Hai bên |

**Khuyến nghị:** không mở nhánh code nào cho đề xuất này trước khi (a) chốt thứ tự merge chuỗi `tmp/ui-preview`, (b) Dev1 trả lời PR-C. Phần P0 bên dưới được chọn để **không cần bảng mới**.

---

## 2. Định hướng mới bạn nêu — diễn giải thành nguyên tắc thiết kế

> "Con người kiểm soát được (trang quản lý), AI len lỏi vào cập nhật tài liệu/báo cáo; có vùng riêng để xem AI hoạt động; vừa cho AI chạy tự chủ có điều phối, vừa cho con người can thiệp từng giai đoạn."

Gói thành **5 nguyên tắc** (bổ sung, không thay `DESIGN_BRIEF` P1–P6):

| # | Nguyên tắc | Nghĩa là gì trong giao diện |
|---|---|---|
| C1 | **Trang là nguồn sự thật, AI là người cộng tác ghi vào trang** | AI không có "màn hình riêng chứa kết quả". Kết quả của AI nằm **ngay trên trang nghiệp vụ** (báo cáo audit nằm ở Audit, gap nằm ở Đối thủ), có nhãn "AI · chạy lúc … · nguồn …". |
| C2 | **Mỗi giai đoạn có 3 chế độ rõ ràng** | `Thủ công` (chỉ người làm) · `AI đề xuất, người duyệt` (mặc định) · `AI tự chạy trong giới hạn` (chỉ khi có policy được duyệt). Hiển thị chế độ hiện hành bằng một chip cố định trên đầu mỗi giai đoạn. |
| C3 | **Can thiệp tại chỗ, không đi vòng** | Chọn đoạn → ô yêu cầu sửa; nút "Khám phá lại / Phân tích đối thủ" ngay trên khối kết quả; "Thêm nguồn / Cung cấp thêm" ngay chỗ AI báo thiếu dữ liệu. |
| C4 | **Vùng hoạt động của AI = nhật ký có cấu trúc, không phải chatbot** | Giữ `DESIGN_BRIEF` P6 (không avatar, không lời chào). Vùng này là **Activity/Runs**: ai/agent nào, làm gì, dựa nguồn nào, tốn bao nhiêu, kết quả, nút dừng/làm lại. |
| C5 | **Trung thực về trạng thái tính năng** | Mỗi nút/khối có đúng một trong 5 nhãn trạng thái (§3). Nút chưa nối backend phải **disabled kèm lý do**, tuyệt đối không bấm được rồi báo thành công. |

Căng thẳng cần nêu rõ: bạn muốn AI "len lỏi", nhưng `AGENTS.md` §1.3–1.4 và kiến trúc (§4.1, §10) cấm AI tự publish, tự gọi công cụ ngoài allowlist và yêu cầu người duyệt trước khi đăng. Thiết kế dưới đây giữ nguyên các ràng buộc đó: **AI tự chủ ở các bước nghiên cứu/đề xuất/nháp, không tự chủ ở bước xuất bản**. Chế độ `AI tự chạy` chỉ áp dụng tới nháp, không bao giờ tới publish ở giai đoạn này.

---

## 3. Bảng nhãn trạng thái tính năng (bắt buộc dùng, giải quyết yêu cầu 5)

Dùng `StatusPresenter` đã có (nhánh `tmp/ui-preview`) mở rộng thêm nhóm "mức sẵn sàng". Năm nhãn, một màu/icon cố định:

| Nhãn | Màu (token) | Khi nào dùng | Hành vi nút |
|---|---|---|---|
| **Đang hoạt động** | `--ok` | Có backend thật, đã chạy trên dữ liệu thật, có bằng chứng | Bấm được |
| **Dữ liệu minh họa** | `--info` + viền nét đứt | Giao diện chạy trên số mẫu (`DemoMode`), **không** có nguồn thật | Bấm được nhưng mọi kết quả gắn dải "MINH HỌA" |
| **Chưa kết nối** | `--warn` | Có giao diện, thiếu nguồn/API/credential | **Disabled** + tooltip "cần gì, ai cấp" |
| **Đang phát triển** | `--neutral` | Có trong kế hoạch gốc (mã việc), chưa xong | Ẩn nút; chỉ hiện thẻ mô tả + mã việc |
| **Ý tưởng** | `--outline` | Đề xuất cải thiện, chưa được duyệt/estimate (vd ARCH-xx) | Chỉ hiện trong trang "Lộ trình", **không** hiện trên màn làm việc |

Quy tắc cứng:
1. Màn làm việc hằng ngày **không** chứa nút ở trạng thái `Ý tưởng`. Chúng chỉ nằm ở trang "Lộ trình sản phẩm" (P1) để không ai tưởng đã có.
2. Dải `MINH HỌA` phải nằm trong vùng nhìn thấy (không chỉ ở footer), tồn tại ở mọi màn đang chạy `DemoMode`, và biến mất ở production (đã khớp quy tắc trong `DemoMode.php`).
3. Mock/Live AI: mọi đoạn nội dung AI sinh phải có chip `Mock` hoặc `Live · <model>` (khớp cảnh báo kiến trúc §2.1 về fallback Gemini→Mock im lặng).
4. Không hiển thị `0` khi thiếu dữ liệu — dùng "Chưa có dữ liệu" (khớp `USER_EXPERIENCE_ROADMAP` §4 Giai đoạn 3).

### 3.1 Phân loại hiện trạng theo nhãn trên (đánh giá của tôi, cần Dev2 xác nhận)

| Khu vực | Quan sát trên `:8101` | Nhãn đề xuất | Ghi chú |
|---|---|---|---|
| Tổng quan (hàng chờ quyết định) | Có danh sách 17 việc, badge menu | Đang hoạt động (trên dữ liệu seed) | Tên bài toàn "[Mẫu TQ]" → dữ liệu mẫu |
| Duyệt bài + Kiểm chất lượng | Panel P0/P1 với lỗi cụ thể, ngưỡng đo được | Đang hoạt động (quy tắc), nội dung demo | Điểm mạnh nhất hiện tại |
| Lịch tuần / Lịch xuất bản | Có | Đang hoạt động trên seed | Chưa kiểm chứng đối soát thật |
| Từ khóa, Kế hoạch nội dung | Có lưới tuần, cụm chủ đề | Dữ liệu minh họa | Kéo thả đã có |
| Audit website | 40/100, 10 P0, bảng nhóm lỗi | Dữ liệu minh họa (nguồn `DemoSeoAuditSource`) | Code còn thông báo "Chưa kết nối crawler" khi không demo — đúng hướng; cần kiểm tra dải MINH HỌA thấy rõ |
| Search Console | Có | Dữ liệu minh họa / Chưa kết nối | Cần xác nhận |
| Đối thủ & khoảng trống | Số "~3.800đ", bảng 7 khoảng trống, "tuyensinh-hocbong.vn" | Dữ liệu minh họa | Có `CostPolicy`, `KeywordGapSource` → hướng đúng |
| Website kết nối | 4 site, trạng thái kết nối | Đang hoạt động; nút "Kiểm tra kết nối" cần PR-D | |
| Chi phí AI | Có (dữ liệu `cost_vnd`) | Đang hoạt động trên seed | |
| **Vùng hoạt động AI / Runs** | Không tồn tại | Ý tưởng (ARCH-02) | |
| **Soạn bài từng đoạn (workshop)** | Không tồn tại (chỉ có màn *Xem phiên bản*) | Đang phát triển (3.4) → mockup có | |
| **Chiến dịch (nhóm bài)** | Menu gọi "Chiến dịch & Bài viết" nhưng là danh sách bài | Đang phát triển (chờ bảng `campaigns`) | |
| **n8n / workflow** | Không tồn tại | Ý tưởng; n8n `SEO web` chưa có node (theo roadmap §9) | |

---

## 4. Đối chiếu UI hiện tại ↔ Design System/mockup

### 4.1 Đã khớp tốt
- Màu teal `#0F6B5C`, nền trắng ấm, nút primary teal, badge trạng thái nhỏ, nhãn tiếng Việt, tên "SEO Studio".
- Điều hướng đã nhóm 5 nhóm (Chiến dịch · Lịch đăng · Khám phá · Phân tích · Cài đặt) đúng tinh thần brief §4; có badge số (17, 3).
- Kiểm chất lượng P0/P1/P2 có bằng chứng "Đo được / Ngưỡng" — đúng P1/P2 của brief, tốt hơn cả mockup ở chỗ này.
- Tổng quan trả lời "hôm nay tôi cần làm gì" (tiêu đề "Hôm nay có 20 việc cần bạn quyết") — đúng mockup `dashboard`.

### 4.2 Lệch / thiếu so với mockup (quan sát thực tế)

| # | Phát hiện | Bằng chứng | Mức |
|---|---|---|---|
| D1 | **Tiêu đề trang không nhất quán với menu**: menu "Duyệt bài" → tiêu đề "Phiên Bản Bài"; "Xem Phiên Bản Bài"; "Danh Sách Website" (viết hoa từng chữ kiểu mặc định Filament) so với mockup "Duyệt bài", "Website kết nối" | Ảnh chụp `/article-versions`, `/websites` | Cao (dễ sửa) |
| D2 | **Màn Duyệt bài là màn xem-chỉ-đọc**, thiếu workspace: mockup `article-review`/`article-workshop` có thanh tiến trình trạng thái, lịch sử phiên bản, so sánh bản, sửa **từng đoạn** với *Viết lại / Rút gọn / Mở rộng / Thêm nguồn*, panel nguồn đã gắn, cấu trúc | `/article-versions/198` chỉ có "Nội dung bài", "Quyết định", "Kiểm chất lượng" | **Cao** (lõi của hướng mới) |
| D3 | Mockup Tổng quan có **KPI trong ngữ cảnh** ("Đã đăng tuần này 14, +3"), bảng chiến dịch, nhịp đăng tuần, hoạt động gần đây gồm cả hoạt động của **hệ thống/AI**; bản thật chỉ có hàng chờ + vài widget (cần xác nhận lúc tải xong) | Ảnh chụp `/admin` | Trung bình |
| D4 | Mockup có **Chiến dịch** là điểm vào chính (tiến độ, chi phí, tạm dừng); bản thật không có | Menu | Cao (nhưng phụ thuộc DB) |
| D5 | Mockup có **⌘K command palette chứa hành động**; bản thật chỉ có ô "Tìm kiếm" | Topbar | Thấp (đã ghi V1.2 trong plan) |
| D6 | **Dữ liệu seed lộ liễu** làm giảm cảm nhận chất lượng: hàng loạt "[Mẫu TQ] chờ duyệt 1…17", nội dung "Nội dung mẫu" khi mở bài | Ảnh chụp | Trung bình (chỉ ảnh hưởng demo/UAT) |
| D7 | Bảng bài có cột `Token`/`Chi phí` rất sớm trên danh sách duyệt — đúng cho quản lý, thừa cho người duyệt; cột bị cắt ở ngoài lề | `/article-versions` | Thấp |
| D8 | Trang Audit/Đối thủ có nhiều khối (4 thẻ chỉ số + tab + 2 bảng song song) → mật độ cao, chưa có trạng thái "đang quét/AI đang phân tích" thấy ngay | Ảnh chụp | Trung bình |
| D9 | Chưa thấy dải **MINH HỌA** rõ ràng ở các màn dùng dữ liệu mẫu (component `demo-banner` có trong repo; chưa xác nhận đã được dùng) | Ảnh Audit/Đối thủ | **Cao** (liên quan trung thực) |
| D10 | Chưa kiểm tra: mobile, dark mode, focus bàn phím, Esc đóng slide-over | — | Chưa chạy |

---

## 5. Ý tưởng thiết kế mới — "AI len lỏi, người cầm lái"

### 5.1 Bản đồ tổng thể: ba tầng xuất hiện của AI

```
Tầng 1 — TRONG TRANG (len lỏi)        Tầng 2 — NÚT HÀNH ĐỘNG        Tầng 3 — VÙNG HOẠT ĐỘNG AI
Báo cáo/đề xuất nằm trên trang        Khám phá lại · Phân tích       "Hoạt động AI" (Runs) + Ngăn kéo
nghiệp vụ, có nhãn AI+nguồn+giờ       đối thủ · Gom cụm lại ...      theo dõi từng lần chạy, dừng/làm lại
Chọn đoạn → ô yêu cầu sửa             Mỗi nút = 1 "tác vụ" có chi    Người xem luồng, chi phí, nguồn
Báo thiếu dữ liệu → "Thêm nguồn"      phí ước tính + xác nhận        Không phải chatbot (giữ P6)
```

### 5.2 Mẫu tương tác chuẩn: **Khối AI** (AI Block)

Một component dùng chung cho mọi báo cáo/đề xuất của AI trên các trang:

```
┌ AI · Phân tích khoảng trống đối thủ   [Live · gemini-… ]  [Chạy lúc 09:14 · 3 nguồn]  ┐
│ Tóm tắt 3 dòng (do AI viết)                                                           │
│ ▸ Căn cứ (7)      ▸ Giới hạn dữ liệu (2)      ▸ Chi phí: 4.120 token · ~38đ           │
│───────────────────────────────────────────────────────────────────────────────────────│
│ [Khám phá lại]  [Thêm nguồn…]  [Yêu cầu chỉnh…]        Chấp nhận ▾  | Bỏ qua | Ghim    │
└───────────────────────────────────────────────────────────────────────────────────────┘
```
- Header luôn có: **vai trò (Auditor/Researcher…) · chế độ Mock/Live · thời điểm · số nguồn**. (Đáp ứng P6 "để lại dấu vết").
- "Căn cứ" mở ra danh sách nguồn kèm trích đoạn; thiếu nguồn thì hiện "Chưa có nguồn" màu cảnh báo, không giấu.
- "Giới hạn dữ liệu" là bắt buộc (khớp kiến trúc §14: không biến missing thành 0).
- Hành động tách bạch theo P3: **Chấp nhận** (ghi vào kế hoạch/bản ghi) ≠ **Yêu cầu chỉnh** ≠ **Chạy lại** (tốn chi phí, có xác nhận).

### 5.3 Sửa nội dung theo vùng chọn (đúng ý "chọn phần cần chỉnh + ô nhập yêu cầu")

Trong workspace bài:
1. Người chọn đoạn (hoặc nhiều đoạn) → hiện **thanh nổi nhỏ** *Viết lại · Rút gọn · Mở rộng · Thêm nguồn · Yêu cầu khác…* (khớp mockup `editor__ai`).
2. "Yêu cầu khác…" mở **ô nhập một dòng** + gợi ý nhanh ("giọng trang trọng hơn", "thêm ví dụ", "bám nguồn 2").
3. Kết quả hiện **dạng diff ngay trên đoạn** (cũ/mới), người chọn *Giữ bản mới / Giữ bản cũ / Thử lại*. Dùng `ParagraphDiff` đã có.
4. Mỗi lần sửa ghi 1 dòng vào lịch sử phiên bản "AI viết lại đoạn 3 theo yêu cầu: …" — người duyệt thấy chính xác AI đã chạm vào đâu.
5. Đoạn có **claim số liệu chưa có nguồn** được gạch chân vàng, kèm "Thêm nguồn" (mockup có ví dụ "Học phí · chưa có nguồn").

Trạng thái: **mockup đã có**; code Filament chưa có (task 3.4). Với Mock provider chỉ hiện chip `Mock`.

### 5.4 Nút giai đoạn (stage controls) — điều khiển ở cấp **giai đoạn**, không chỉ cấp bài

Một **thanh giai đoạn** xuất hiện ở đầu trang Chiến dịch (và thu gọn ở trang con):

```
[1 Hiểu website] → [2 Khám phá từ khóa] → [3 Đối thủ & khoảng trống] → [4 Kế hoạch] → [5 Viết & duyệt] → [6 Xuất bản] → [7 Đo lường]
   ✓ Đã duyệt        ◐ AI đang chạy          ○ Chờ                         ○             ○                 ○ (người)       ○
 Chế độ: AI đề xuất, người duyệt   ·  Chạy lại ▾  ·  Tạm dừng  ·  Cung cấp thêm…
```
Mỗi giai đoạn có: **chế độ** (C2), **đầu vào** đã/chưa đủ ("thiếu: 1 file tài liệu"), **đầu ra** (liên kết tới báo cáo), **cổng duyệt** ("Người duyệt giai đoạn này"), và **ba nút người dùng cần**: *Chạy lại*, *Cung cấp thêm nguồn/yêu cầu*, *Khóa kết quả* (không cho AI ghi đè nữa).
Giai đoạn 6 (xuất bản) **luôn khóa chế độ `người`** ở phiên bản này.

### 5.5 Vùng hoạt động AI — "Hoạt động AI" (mức độ ưu tiên cao, nhưng chọn bản gọn)

Hai cấp, tránh xây giao diện registry khổng lồ (khớp kiến trúc §11.3: "không cần UI registry phức tạp ngay"):

**(a) Ngăn kéo "Hoạt động AI" mở từ biểu tượng ở topbar** (mọi trang):
- Danh sách `run` gần nhất của ngữ cảnh hiện tại: trạng thái (`queued/running/succeeded/failed/needs_input/needs_review/cancelled/budget_exceeded` — đúng enum kiến trúc §7.3), tác vụ, người kích hoạt, thời gian, chi phí.
- Mục `needs_input` nổi lên đầu: "AI cần bạn: thiếu danh sách đối thủ" + nút trả lời tại chỗ.

**(b) Trang "Hoạt động AI" (menu Cài đặt hoặc Phân tích)** — xem một lần chạy:
```
Run #482 · Phân tích đối thủ · blog.company.vn · Live gemini-… · 09:14 → 09:16 · 4.120 token · ~38đ
  ● Nhận mục tiêu        ✓ 09:14   input: 3 đối thủ                           [xem input]
  ● Thu thập SERP        ✓ 09:15   2/3 nguồn đọc được, 1 bị chặn (SSRF allowlist) [xem log]
  ● Tổng hợp khoảng trống ✓ 09:16   7 khoảng trống · độ tin cậy trung bình
  ● Kiểm tra schema/nguồn ⚠ 09:16   2 khoảng trống chưa có nguồn → needs_review
  [Chấp nhận kết quả] [Chạy lại bước 3] [Hủy]
```
- Mỗi bước: input/output **đã che secret**, prompt version, nguồn đã dùng, quyết định chính sách ("công cụ X bị từ chối: ngoài allowlist").
- Đây là nơi "con người xem AI hoạt động thế nào, luồng ra sao" bạn yêu cầu.

**Trạng thái:** chưa có bảng run (ARCH-02 do Dev1). **Giai đoạn đầu (P1) dùng dữ liệu có thể suy ra từ `ArticleVersion` (provider/model/token/cost/prompt_version) + `AuditRecorder` đã có**, nhãn `Đang phát triển`, không giả bảng run.

### 5.6 Trực quan hóa workflow/n8n (yêu cầu 4)

Nguyên tắc: **một sơ đồ, ba trạng thái dữ liệu** — cùng sơ đồ nhưng mỗi nút mang nhãn §3.

- **Bản đồ luồng (read-only)** ở trang "Lộ trình/Cách hoạt động": nút hình chữ nhật hairline, nối mũi tên; mỗi nút có chip trạng thái (Đang hoạt động / Đang phát triển / Ý tưởng). Dựa trên sơ đồ trong `USER_EXPERIENCE_ROADMAP` §5.2 và `flows.html` của design-system. Hiện tại phần lớn là `Ý tưởng`; **n8n `SEO web` chưa có node** → nút n8n gắn rõ "Chưa cấu hình".
- **Bản chạy (run view)** ở trang Hoạt động AI (§5.5): cùng bố cục nhưng nút có trạng thái thật theo từng lần chạy, `correlation_id`, thời gian, nhánh lỗi tách riêng (validation / auth / provider / quality / CMS / timeout — đúng roadmap §5.3).
- Không vẽ mũi tên "tự động" ở nơi chưa nối: cạnh nối chưa có backend vẽ nét đứt + chú thích.
- **Không** dựng trình soạn workflow kéo-thả. n8n tự có UI soạn; ta chỉ hiển thị trạng thái đã đối soát qua API Laravel.

### 5.7 Ánh xạ bộ skill `claude-seo` → nút/khối giao diện

`claude-seo` là **plugin Claude Code dạng CLI** (Python 3.11+, Chromium, chạy lệnh `/seo ...`), không phải dịch vụ web. Vì vậy dưới đây là **tham chiếu nghiệp vụ**, chưa phải cam kết tích hợp. Cách dùng thực tế phải do Dev1 quyết (chạy qua runner cô lập? tái hiện logic bằng service Laravel?) và phải qua SSRF allowlist.

| Lệnh `/seo` (nguồn README) | Điểm đặt trong UI đề xuất | Nhãn hiện tại | Kết quả hiển thị |
|---|---|---|---|
| `audit <url>` (nhiều agent song song, hành động ưu tiên) | Nút **Quét lại website** trên *Audit website* | Dữ liệu minh họa; crawler thật = task 1.1 (Dev1) | Khối AI: nhóm lỗi P0/P1/P2 + "cách kiểm chứng đã sửa" |
| `page <url>` | Hàng lỗi → "Phân tích trang này" | Ý tưởng | Slide-over phân tích một URL |
| `technical`, `sitemap`, `schema`, `images` | Tab con trong Audit | Ý tưởng/một phần có trong 1.1/1.3 | Bảng lỗi theo nhóm |
| `cluster <keyword>` | **Gom cụm lại** trên *Từ khóa* | Có UI (MockClusterer) | Cụm + intent, nhãn Mock/Live |
| `content-brief <topic>` | **Tạo brief** từ cụm/lỗi | Có nút "Tạo brief từ lỗi" (mockup/Audit) | Brief để người sửa trước khi giao Writer |
| `plan <type>` | **Lập chiến lược** đầu chiến dịch | Ý tưởng (ARCH-06/07) | Thẻ chiến lược + lý do ưu tiên |
| `competitor-pages` (tạo trang so sánh) | Không phải "phân tích đối thủ" — **tránh nhầm**; trang *Đối thủ* cần lệnh khác | — | Cảnh báo: tên lệnh dễ gây hiểu nhầm |
| `google` (GSC, PageSpeed, CrUX, GA4) | **Kết nối Search Console** + tab Hiệu suất | Chưa kết nối (5.1) | So sánh kỳ, bài tụt hạng |
| `drift baseline/compare` | **So với lần quét trước** (delta) trên Audit | Ý tưởng | Mũi tên ▲▼ theo lỗi (đã có cột ▲▼ ở Audit demo) |
| `content` (E-E-A-T, chất lượng) | Panel *Kiểm chất lượng* mở rộng | Một phần (3.5/6.1) | Quy tắc + bằng chứng |
| `geo`, `agentic`, `local`, `maps`, `backlinks`, `ecommerce`, `hreflang`, `programmatic` | **Không đưa vào V1 UI** | Ý tưởng/ngoài phạm vi | Chỉ liệt kê trong "Lộ trình" |

Điều rút ra cho thiết kế (không phụ thuộc việc có tích hợp hay không): `claude-seo` tổ chức kết quả theo **phát hiện → mức độ → hành động ưu tiên → cách xác minh**; UI Audit/Đối thủ nên theo đúng cấu trúc bốn tầng này (hiện Audit đã có hai tầng đầu).

---

## 6. Màn hình/thành phần còn thiếu hoặc nên cải thiện

### P0 — nên làm trước, ít phụ thuộc, tăng niềm tin ngay
| ID | Hạng mục | Mô tả | Phụ thuộc | Nhãn khi ra mắt |
|---|---|---|---|---|
| P0-1 | **Dải trạng thái + chip Mock/Live thống nhất** | Component `status-ribbon` & `ai-source-chip`; áp lên mọi màn demo. Giải D9, quy tắc §3 | Không (Dev2 + `StatusPresenter`) | Đang hoạt động |
| P0-2 | **Chuẩn hóa tiêu đề trang** | Sửa D1 (`Duyệt bài`, `Website kết nối`…), breadcrumb nhất quán | Không | — |
| P0-3 | **Khối AI (AI Block)** dùng chung | Header + căn cứ + giới hạn + hành động tách bạch (§5.2). Đưa vào Audit, Đối thủ, Gom cụm | Không bảng mới | Dữ liệu minh họa |
| P0-4 | **Nút can thiệp tại chỗ ở các báo cáo hiện có** | "Chạy lại", "Thêm nguồn", "Yêu cầu chỉnh" gắn vào Audit/Đối thủ/Kế hoạch; mọi nút `disabled` + lý do khi chưa nối | Không | Chưa kết nối (disabled có lý do) |
| P0-5 | **Trang "Cách hoạt động / Lộ trình"** | Bản đồ luồng + bảng trạng thái từng module (§3.1, §5.6); nơi duy nhất chứa nhãn `Ý tưởng` | Không | — |
| P0-6 | **Dọn dữ liệu seed hiển thị** | Seed demo có kịch bản (tên bài thật, nội dung đủ dài) thay "[Mẫu TQ]…" để UAT nhìn đúng | Không (Dev2 seeder trong test/demo) | Dữ liệu minh họa |

### P1 — giá trị lớn, cần phối hợp hoặc hoàn tất task đang chạy
| ID | Hạng mục | Phụ thuộc |
|---|---|---|
| P1-1 | **Workspace bài (Soạn bài)** theo `article-workshop`: sửa theo đoạn + diff + nguồn + lịch sử (§5.3) | Task 3.4; dùng `ParagraphDiff`, `ArticleVersion` hiện có |
| P1-2 | **Thanh giai đoạn + chế độ** (§5.4) trên "Chiến dịch" tạm gom theo `topic_cluster`, ghi rõ tạm | Chưa cần bảng (PR-C để đầy đủ) |
| P1-3 | **Ngăn kéo Hoạt động AI** gọn (§5.5a) từ dữ liệu hiện có | Không bảng mới; đầy đủ cần ARCH-02 |
| P1-4 | **Tổng quan đúng mockup**: KPI trong ngữ cảnh + nhịp đăng + hoạt động gồm "hệ thống/AI" | Dữ liệu query sẵn |
| P1-5 | **Tách lại menu "Chiến dịch & Bài viết"** thành Chiến dịch / Bài viết thật sự khi có `campaigns` | PR-C (Dev1) |
| P1-6 | **Xác nhận chi phí trước khi chạy tác vụ tốn phí** (modal: ước tính token/đ, nguồn dữ liệu, ngưỡng còn lại) | `CostPolicy`, `AiUsage` đã có |

### P2 — tầm nhìn, chờ nền tảng
| ID | Hạng mục | Phụ thuộc |
|---|---|---|
| P2-1 | **Trang Hoạt động AI đầy đủ + run view workflow** (§5.5b, §5.6) | ARCH-02, ARCH-06 (Dev1) |
| P2-2 | **Kho kiến thức (Knowledge Base)**: tải tài liệu, trạng thái duyệt/hết hạn, trích dẫn tới chunk | ARCH-04 |
| P2-3 | **Chế độ "AI tự chạy trong giới hạn"** cho nghiên cứu/nháp, kèm ngân sách & dừng khẩn cấp | Policy được duyệt, kill switch thật (3.6) |
| P2-4 | **⌘K palette có hành động** ("Chạy lại audit site X", "Duyệt bài đang mở") | Sau P1 |
| P2-5 | **Hiệu suất sau xuất bản → đề xuất refresh** (Performance Analyst) | 5.1–5.3 |
| P2-6 | **Điều khiển/giám sát n8n** (nếu Dev1 chọn n8n) | Dev1 duyệt boundary webhook |

---

## 7. Bố cục & phân cấp thông tin cụ thể (ví dụ tham chiếu Design System)

Mỗi dòng: *thành phần cần* → *class/token đã có để tái dùng* (từ `components.css` / `tokens.css` / `layout.css`). Mục tiêu: không phát minh style mới.

| Thành phần đề xuất | Tái dùng từ Design System | Nguồn mockup |
|---|---|---|
| Khối AI (§5.2) | `.panel` + `.panel__head`/`.panel__meta` + `.badge--info` + `.kv--inline` + `.btn--ghost`/`.btn--secondary` (một primary duy nhất) | `competitors.html`, `seo-audit.html` |
| Chip Mock/Live | `.badge--outline`, `.badge--dot` | `styleguide.html` |
| Dải MINH HỌA / Chưa kết nối | `.banner` + `.banner--warn` + `.banner__icon` | `styleguide.html` |
| Thanh giai đoạn (§5.4) | `.b-tabs`/`.b-tab` hoặc `.progress`+`.progress__bar--ok/--warn` | `campaigns.html` (tiến độ), `flows.html` |
| Hàng đợi quyết định | `.qrow` (layout.css) | `dashboard.html` |
| Thanh chọn đoạn + AI | `.editor__ai`, `.editor__block-actions`, `.editor__block--hovered` | `article-workshop.html` |
| Ô yêu cầu sửa một dòng | `.field` + `.field__input` + `.field__hint` + `.kbd` (⏎) | `styleguide.html` |
| Danh sách nguồn/căn cứ | `.kv`, `.chip`, `.chip__remove` | `article-workshop.html` ("Nguồn đã gắn") |
| Ngăn kéo Hoạt động AI | `.drawer`, `.drawer__head/__body/__foot` | `styleguide.html` |
| Xác nhận tác vụ tốn phí | `.modal--sm`, `.meter`/`.meter--warn` cho ngưỡng | `ai-usage.html` |
| Trạng thái rỗng "Chưa có dữ liệu" | `.empty`, `.empty--inset`, `.empty__actions` | `styleguide.html` |
| Điểm audit | `.score-ring`, `.score-ring--lg` | `seo-audit.html` |
| Palette ⌘K | `.cmdk`, `.cmdk__item`, `.cmdk__group-label` | `styleguide.html` |

**Phân cấp thông tin mỗi trang (quy ước):**
1. Dòng 1 (tiêu đề + chip trạng thái + **một** nút primary).
2. Dòng 2: thanh giai đoạn/bộ lọc (ngữ cảnh).
3. Thân: nội dung nghiệp vụ; **Khối AI nằm ở đầu thân** nếu AI vừa chạy, còn không thì thu gọn dưới thanh tiêu đề.
4. Phụ: panel "Căn cứ & giới hạn" (thu gọn mặc định — progressive disclosure).

**Wireframe Audit sau khi áp dụng (minh họa, chưa dựng):**
```
Audit website                [blog.company.vn ▾]  [Dữ liệu minh họa]        ( Quét lại ▸ )   ← 1 primary
┌ Khối AI · Tóm tắt quét 06/11 09:14  [Mock]  3 nguồn ─────────────────────────────────────┐
│ 10 lỗi P0 tập trung ở "Liên kết gãy (404)" và "Thiếu title". Gợi ý xử lý trước: …          │
│ ▸ Căn cứ  ▸ Giới hạn: chưa có dữ liệu Core Web Vitals     [Chạy lại] [Thêm nguồn] [Chấp nhận]│
└────────────────────────────────────────────────────────────────────────────────────────────┘
[Nhóm lỗi 18] [Brief đã tạo 0] [Quá trình]     ← b-tabs
bảng nhóm lỗi (có ▲▼ so với lần trước)        |  slide-over: URL bị ảnh hưởng + bằng chứng
```

---

## 8. Điểm không nhất quán / rủi ro hiểu nhầm cần xử lý

| # | Rủi ro | Xử lý đề xuất |
|---|---|---|
| R1 | Số liệu mẫu trông như thật (Audit 40/100, Đối thủ "~3.800đ", `tuyensinh-hocbong.vn`) | P0-1: dải MINH HỌA + chip nguồn; ẩn điểm tổng khi không đủ dữ liệu (khớp roadmap §4 GĐ3) |
| R2 | Nút "Gom cụm bằng AI", "Làm mới dữ liệu", "Quét lại" gợi ý AI/crawler thật đang chạy | Nút disabled + lý do nếu provider là Mock/`DemoSource`; chip Mock/Live |
| R3 | Fallback Gemini→Mock im lặng (kiến trúc §2.1) | UI phải hiện chip `Mock` mọi nơi; không tính vào nghiệm thu |
| R4 | Menu "Chiến dịch & Bài viết" gợi ý đã có Chiến dịch | Đổi nhãn tạm "Bài viết" đến khi có `campaigns`; ghi chú "gom tạm theo chủ đề" |
| R5 | `competitor-pages` của claude-seo ≠ phân tích đối thủ | Không dùng chung tên nút; đặt tên theo việc ("Khám phá đối thủ", "Phân tích khoảng trống") |
| R6 | Thanh giai đoạn/chế độ `AI tự chạy` gợi ý AI tự đăng | Giai đoạn Xuất bản khóa chế độ `Người`; không có lựa chọn "tự đăng" ở V1 |
| R7 | Vùng Hoạt động AI giả lập dữ liệu run để "cho đẹp" | Bắt buộc dùng nhãn `Đang phát triển` hoặc dữ liệu suy ra thật; không bảng run giả |
| R8 | Nhiều nút AI mỗi trang → quá tải, vi phạm P4 (một primary) | Mỗi trang 1 primary; AI action là secondary/ghost, gom "Thêm ▾" |
| R9 | Hiển thị log/nguồn lộ secret hoặc nội dung crawl thô (prompt injection/XSS) | Chỉ hiển thị trích đoạn đã sanitize, `iframe sandbox`, che secret (AGENTS §1) |
| R10 | Người dùng nhầm "Chấp nhận kết quả AI" với "Duyệt xuất bản" | Hai nút khác tên, khác màu, khác trang (P3) |

---

## 9. Lộ trình áp dụng (sau khi được duyệt)

**Điều kiện trước (giai đoạn 0, ≈ 0,5–1 ngày, không code):**
1. Chốt thứ tự merge chuỗi nhánh Dev2 (`tmp/ui-preview` → `develop`) để tránh xung đột.
2. Dev2 + bạn duyệt: bảng nhãn §3, danh sách P0.
3. Dev1 trả lời PR-C và 4 câu: (a) bảng `ai_runs` (ARCH-02) có nằm trong PR-C không; (b) `claude-seo`/n8n chạy ở đâu và qua boundary nào; (c) allowlist cho `AdminPanelProvider`/`resources/css`; (d) kill switch (3.6) có hạn ngày.

| Đợt | Nội dung | Ước lượng thô* | Phụ thuộc | Kiểm chứng tối thiểu |
|---|---|---:|---|---|
| **A — Niềm tin & nhất quán** | P0-1, P0-2, P0-5, P0-6 | 1,5–2 ngày | Không | Test Livewire/`assertSee` nhãn; chụp ảnh trước/sau (375/768/1440); không có nút ở trạng thái `Ý tưởng` trên màn làm việc |
| **B — Khối AI & nút can thiệp** | P0-3, P0-4 (áp lên Audit, Đối thủ, Từ khóa) | 2–3 ngày | A | Mọi nút chưa nối đều disabled + lý do (test); chip Mock/Live hiện đúng |
| **C — Workspace bài** | P1-1 (+ P1-6) | 4–6 ngày | Task 3.4 | Diff theo đoạn, ghi lịch sử; claim chưa nguồn gạch chân; Esc/Tab/focus |
| **D — Giai đoạn & Hoạt động AI gọn** | P1-2, P1-3, P1-4 | 3–5 ngày | A, B; Dev1 PR-C cho phần đầy đủ | Không giả bảng run; nhãn `Đang phát triển` |
| **E — Nền tảng agent** | P2-1, P2-2, P2-6 | Theo estimate của ARCH-xx | ARCH-02/04/06 (Dev1) | Cần staging thật |
| **F — Hoàn thiện** | P1-5, P2-3, P2-4, P2-5 | — | Sau E | — |

\* Ước lượng thô của người thiết kế, **chưa** được Dev2 estimate, **chưa** thêm vào `TASKS.md`, và không cộng vào 432h gốc (theo kiến trúc §11.7).

**Quy trình mỗi đợt (theo `AGENTS.md`):** nhánh `<loại>/<Mxx>/<mã>/dev2/<slug>` cắt từ `develop` mới nhất; chỉ sửa trong allowlist; PR ghi 4 mức bằng chứng và mục "Chưa chạy"; Dev1 review chéo; không commit thẳng `main`/`develop`.

---

## 10. Câu hỏi cần bạn/Dev quyết trước khi triển khai

1. **Thứ tự merge:** merge chuỗi nhánh Dev2 theo nhóm nào trước (khuyến nghị: shell/ui-kit → Websites → Duyệt bài → Lịch → SEO pages)? Ai chịu trách nhiệm gộp?
2. **Vùng Hoạt động AI:** hài lòng với phiên bản *gọn trước* (ngăn kéo + suy ra từ dữ liệu có sẵn) hay đợi `ai_runs` của Dev1?
3. **`claude-seo`:** chỉ dùng làm *tham chiếu nghiệp vụ* hay muốn tích hợp chạy thật? Nếu thật, đây là việc của Dev1 (runner cô lập, SSRF, chi phí) và cần task mới.
4. **Chế độ `AI tự chạy`:** đồng ý giới hạn ở nghiên cứu/nháp (không bao giờ tự publish) ở giai đoạn này?
5. **Tên nhãn trạng thái §3:** dùng nguyên văn hay đổi cho hợp thuật ngữ Marketing?
6. **Ngôn ngữ giao diện:** vẫn chỉ tiếng Việt (brief §7 Q4)?
7. **Cho phép đụng** `app/Providers/Filament/AdminPanelProvider.php` và `resources/css|views` trong PR Dev2 không (Q1 của plan cũ)?

---

## 11. Những việc tôi đã KHÔNG làm (để bạn yên tâm)

- Không sửa/ghi đè file code, view, CSS, migration, seeder; không đổi giao diện; không tạo nhánh/PR/commit.
- Không thay đổi dữ liệu thật: chỉ đăng nhập tài khoản seed trên bản xem thử cục bộ `127.0.0.1:8101` và xem trang (không bấm hành động ghi). Nếu thấy nhật ký đăng nhập, đó là phiên xem này.
- Không đổi trạng thái `TASKS.md`; các mã `ARCH-xx` và P0/P1/P2 ở đây là **đề xuất**, chưa phải cam kết WBS.
- Không dùng/cài `claude-seo`; nội dung về nó chỉ từ README công khai.

---

## 12. KỊCH BẢN LUỒNG SỬ DỤNG (đủ các trường hợp có thể xảy ra)

> Bổ sung 06/11/2026 theo yêu cầu. Đây là **kịch bản thiết kế/nghiệm thu giao diện**, không phải khẳng định tính năng đã chạy. Cột "Tình trạng" lấy theo nhãn §3 và quan sát ở §3.1; những gì tôi chưa kiểm chứng ghi rõ.

### 12.0 Quy ước đọc

**Vai trò:** `Admin` · `Approver` (trưởng nhóm duyệt) · `Writer` (biên tập) · `Manager` (quản lý Marketing) · `AI` (agent, chỉ đề xuất/nháp) · `Hệ thống` (queue/scheduler/CMS adapter).

**Bộ trạng thái dùng xuyên suốt** (để mọi kịch bản nói cùng một ngôn ngữ):

| Đối tượng | Trạng thái |
|---|---|
| Bài (`workflow_status`) | `planned → drafting → needs_review → approved → scheduled → publishing → published` (+ `rejected`, `needs_revision`) |
| Lần chạy AI (run, theo kiến trúc §7.3) | `queued · running · succeeded · failed · needs_input · needs_review · cancelled · budget_exceeded` |
| Xuất bản (publication) | `queued → publishing → published / failed / needs_reconcile` (+ `held` chỉ khi Dev1 thêm; UI không được hứa trước) |
| Giai đoạn chiến dịch (§5.4) | `chưa bắt đầu · AI đang chạy · chờ bạn · đã duyệt · đã khóa · bị chặn · tạm dừng` |

**Mỗi kịch bản có:** Điều kiện → Luồng (các bước, ai làm) → Giao diện phải nói gì → Kết quả/trạng thái → Tình trạng tính năng. Nguyên tắc chung cho **mọi** kịch bản lỗi: (1) nói rõ chuyện gì xảy ra và *đã/chưa* xảy ra điều gì với dữ liệu; (2) đưa **một** hành động an toàn tiếp theo; (3) không có nút "thử lại" mù với thao tác tạo bài; (4) không che lỗi bằng số 0 hay nội dung Mock.

### 12.1 Ma trận tổng hợp

| Nhóm | Mã | Kịch bản | Loại |
|---|---|---|---|
| A. Thiết lập | S01–S04 | Thêm website, lỗi kết nối, thu hồi/tắt site, thiếu bối cảnh doanh nghiệp | Chuẩn · Lỗi · Rủi ro |
| B. Khám phá | S05–S10 | Audit, bị chặn SSRF, thiếu dữ liệu, import CSV lỗi, khám phá lại đối thủ + chi phí, nguồn đối thủ lỗi | Chuẩn · Lỗi · Thiếu dữ liệu |
| C. Soạn nội dung | S11–S18 | Từ cụm thành bài, sửa theo đoạn, AI trả sai schema, Mock, claim thiếu nguồn, prompt injection, trùng từ khóa, sửa đồng thời | Chuẩn · Lỗi · Bảo mật |
| D. Duyệt | S19–S24 | Duyệt, chặn P0, duyệt ngoại lệ, từ chối, sửa sau khi duyệt, sai quyền | Chuẩn · Chặn · Quyền |
| E. Xuất bản | S25–S31 | Đăng đúng hạn, timeout, lỗi xác thực CMS, site không hoạt động, dừng khẩn cấp, đối soát, nghi trùng | Chuẩn · Lỗi · Rủi ro cao |
| F. Đo lường | S32–S34 | Chưa kết nối GSC, bài tụt hạng → refresh, xung đột khi cập nhật bài live | Thiếu dữ liệu · Chuẩn · Xung đột |
| G. AI chạy tự chủ có điều phối | S35–S42 | Chạy chiến dịch end-to-end, AI cần thêm thông tin, tạm dừng/khóa giai đoạn, chạy lại bước, hủy, vượt ngân sách, run treo, mất kết nối trình duyệt | Chuẩn · Can thiệp · Lỗi |
| H. Xuyên suốt | S43–S46 | Người mới/empty state, demo vs production, nhiều người cùng làm, kiểm toán | Trải nghiệm · Quyền |

### 12.2 Nhóm A — Thiết lập website

| Mã | Điều kiện & luồng | Giao diện phải thể hiện | Kết quả | Tình trạng |
|---|---|---|---|---|
| **S01** Thêm website thành công | `Admin` → Website kết nối → *Tạo mới* → bước 1 thông tin (tên, domain) → bước 2 loại CMS (WordPress/Magento) → bước 3 credential theo đúng trường của CMS → *Kiểm tra kết nối* → Lưu | Wizard 3 bước, bước đang ở đâu; secret luôn che, không hiện lại sau lưu ("•••• đã lưu"); trạng thái `Chờ kiểm tra → Đang hoạt động` | Website `active`; xuất hiện ở bộ chọn website mọi trang | Danh sách/form có; **"Kiểm tra kết nối" thật cần PR-D của Dev1** → chưa nối: nút disabled + lý do, không báo "thành công" giả |
| **S02** Kết nối lỗi | Sai Application Password / domain không tới được / CMS trả 401, 403, 5xx, timeout | Phân loại lỗi (xác thực · mạng · quyền · CMS), mã lỗi, hướng dẫn cụ thể ("tạo lại Application Password ở…"); credential đã nhập **không** bị hiện lại; trạng thái `Lỗi` kèm lần thử cuối | Website không `active`; không bài nào được xếp lịch vào site này | Một phần (trạng thái có); lỗi chi tiết phụ thuộc PR-D |
| **S03** Thu hồi/tắt website khi còn bài đã xếp lịch | `Admin` bấm *Thu hồi* hoặc đặt không hoạt động | Hộp xác nhận nêu **số bài/publication bị ảnh hưởng** và hậu quả thật: hiện tại publication của site không `active` sẽ `failed` (`PublishArticleJob:64`), **chưa có "giữ lại"** (`held` cần migration Dev1). UI chỉ được ghi "sẽ bị lỗi", **không** hứa "tạm giữ" | Site `revoked`; danh sách bài bị ảnh hưởng hiện ở Lịch đăng với lý do | Có logic; "held" = Ý tưởng/chờ PR-C |
| **S04** Chưa có bối cảnh doanh nghiệp/tài liệu nguồn | Tạo chiến dịch khi chưa có hồ sơ doanh nghiệp, giọng thương hiệu, tài liệu | Thẻ "Thiếu đầu vào" ở giai đoạn 1 liệt kê *cần gì – ai cấp – ảnh hưởng gì*; cho chọn "Tiếp tục không có tài liệu" nhưng gắn cảnh báo "AI sẽ không có nguồn nội bộ; mọi số liệu cần nguồn" | Giai đoạn 1 `bị chặn` hoặc `tiếp tục có cảnh báo` (người chọn) | Knowledge Base = Ý tưởng (ARCH-04): giai đoạn này chỉ hiện form ghi chú + nhãn `Đang phát triển` |

### 12.3 Nhóm B — Khám phá (audit, từ khóa, đối thủ)

| Mã | Điều kiện & luồng | Giao diện phải thể hiện | Kết quả | Tình trạng |
|---|---|---|---|---|
| **S05** Audit chạy bình thường | `Approver/Admin` → Audit website → chọn site → *Quét lại* → hệ thống quét → ra báo cáo | Trạng thái `đang quét` có tiến độ (số URL/tổng, thời gian); khi xong: Khối AI tóm tắt + bảng lỗi P0/P1/P2, URL ảnh hưởng, bằng chứng, **delta so với lần trước**; chỉ hiện điểm tổng khi đủ dữ liệu | Báo cáo lưu kèm `checked_at`; có nút *Tạo brief từ lỗi* | UI + `DemoSeoAuditSource` có; **crawler thật = task 1.1 (Dev1) chưa xong** → nhãn `Dữ liệu minh họa` |
| **S06** Quét bị chặn (SSRF/ngoài allowlist/IP nội bộ) | URL thuộc domain ngoài allowlist hoặc phân giải ra IP nội bộ/loopback/metadata | Lỗi `ScanBlocked` dạng dễ hiểu: "Domain không nằm trong danh sách được phép" — **không** lộ IP nội bộ hay chi tiết hạ tầng; gợi ý "Nhờ Admin thêm domain" | Không có dữ liệu quét; không tốn chi phí AI | Có `ScanGuard/ScanBlocked` trong nhánh preview (cần kiểm chứng test) |
| **S07** Thiếu dữ liệu để kết luận | Chưa nối GSC/API từ khóa/danh sách đối thủ rỗng | Ô số liệu hiện "Chưa có dữ liệu · cần: kết nối GSC" (không `0`); AI trả `needs_input`/`insufficient_evidence` thành thẻ "AI cần bạn" có ô điền ngay (thêm đối thủ, tải CSV, kết nối nguồn) | Giai đoạn `chờ bạn`; nếu người bỏ qua, kết quả mang nhãn "xếp hạng định tính, không có số liệu" | Một phần (`CostPolicy`, `KeywordGapSource` có) |
| **S08** Import CSV từ khóa lỗi/trùng | `Writer/Approver` tải CSV: sai mã hóa, thiếu cột, trùng từ khóa, số âm, file quá lớn/không phải CSV | Bước **xem trước** trước khi lưu: số dòng hợp lệ/lỗi/trùng, từng lỗi kèm số dòng; cho sửa/bỏ qua/gộp; chặn file sai MIME/quá cỡ | Chỉ dòng hợp lệ được lưu; trùng hiện "đã có ở cụm X" | Có `CsvKeywordParser`, `DuplicateKeywordException`; cần xác nhận UI xem trước |
| **S09** "Khám phá lại"/"Phân tích đối thủ" tốn phí | Người bấm chạy lại | **Modal xác nhận chi phí** (ước tính token/đ, nguồn dữ liệu, mức ngưỡng còn lại bằng `meter`) → xác nhận mới chạy; khi chạy: run `queued→running` hiện trong ngăn kéo Hoạt động AI | Kết quả thay thế **bản cũ vẫn được giữ** (xem lịch sử), không ghi đè mất | Có `CostPolicy`; modal & lịch sử = đề xuất P1-6 |
| **S10** Nguồn đối thủ không khả dụng | Một/nhiều nguồn timeout hoặc bị chặn | Kết quả một phần: "2/3 nguồn đọc được, 1 lỗi: <lý do chung>"; Khối AI ghi **Giới hạn dữ liệu**; nút *Chạy lại nguồn lỗi* | Run `succeeded` có cảnh báo hoặc `needs_review` | `SourceUnavailableException` có; hiển thị từng nguồn = đề xuất |

### 12.4 Nhóm C — Lập kế hoạch & soạn nội dung

| Mã | Điều kiện & luồng | Giao diện phải thể hiện | Kết quả | Tình trạng |
|---|---|---|---|---|
| **S11** Từ cụm thành bài (luồng chuẩn) | `Approver` ở Kế hoạch nội dung chọn cụm → *Tạo bài từ cụm* → tạo `Article planned` → gán ngày/owner → AI tạo dàn ý → bản nháp (`ArticleVersion v1`) | Lý do ưu tiên (cụm nào, intent nào, vì sao); xem trước số bài sẽ tạo trước khi xác nhận; mỗi bài hiện ngay ở đúng cột trạng thái | Bài `drafting → needs_review` khi nháp xong | Có (`PlannedArticleCreator`, lưới tuần); sinh bản nháp qua `generate_ai`; **dữ liệu minh họa** |
| **S12** Sửa theo đoạn có yêu cầu | `Writer` chọn đoạn → *Viết lại/Rút gọn/Mở rộng/Thêm nguồn/Yêu cầu khác* → AI trả đoạn mới → so sánh → *Giữ mới/Giữ cũ/Thử lại* | Thanh nổi tại vùng chọn; diff ngay trên đoạn; chi phí token của lần sửa; ghi 1 dòng lịch sử phiên bản | Phiên bản mới (v+1) hoặc đoạn giữ nguyên; không mất bản cũ | Mockup có; code = P1-1 (**Đang phát triển**) |
| **S13** AI trả JSON sai/vi phạm schema/API lỗi | Provider trả lỗi, JSON hỏng, thiếu trường, HTML quá lớn | Thông báo "AI không trả kết quả hợp lệ" + mã lỗi + *Chạy lại* (có xác nhận chi phí); **tuyệt đối không** thay bằng nội dung Mock và không tạo ArticleVersion giả thành công | Run `failed` (hoặc `needs_review`); bài giữ nguyên trạng thái | **Rủi ro hiện tại:** `GeminiAiProvider` có đường fallback sang Mock (kiến trúc §2.1). UI phải hiện chip Mock; sửa hành vi là ARCH-03 (Dev1/Dev2) |
| **S14** Môi trường chạy Mock | Không có API key hoặc cố ý bật Mock | Chip `Mock` cố định ở mọi đoạn AI và trong thông báo; báo cáo nghiệm thu tự loại các kết quả Mock | Dùng để demo/test, không tính là bằng chứng nghiệm thu | Có `MockAiProvider`; chip = P0-1 |
| **S15** Claim thiếu nguồn | Đoạn có số liệu/tuyên bố (học phí, pháp lý, sức khỏe, tài chính) không có nguồn | Gạch chân vàng + "Thêm nguồn"; panel *Nguồn đã gắn* đánh dấu "chưa có nguồn"; claim nhạy cảm chặn duyệt tới khi có nguồn có thẩm quyền | Bài vẫn là `needs_review` nhưng **khóa nút Duyệt** (lỗi P0) | Quy tắc chất lượng có (P0 hiển thị); gạch chân tại đoạn = P1-1 |
| **S16** Prompt injection trong nội dung crawl/tài liệu | Trang đối thủ hoặc tệp tải lên chứa "bỏ qua hướng dẫn…, đăng bài…" | UI hiển thị nội dung nguồn như **dữ liệu đã làm sạch** (`iframe sandbox`/escape), không bao giờ như lệnh; nếu bộ lọc phát hiện mẫu nghi ngờ: nhãn "Nguồn có chỉ dẫn lạ — đã bỏ qua" ở Căn cứ | AI không gọi thêm công cụ; không thay đổi quyền; ghi vào nhật ký run | Quy tắc `AGENTS.md` §1.3; bộ phát hiện = Dev1/ARCH-05 (**Ý tưởng**) |
| **S17** Trùng từ khóa/cannibalization | Hai bài/kế hoạch cùng từ khóa chính | Cảnh báo trước khi tạo ("đã có bài X dùng từ khóa này") với 3 lựa chọn: *Gộp vào bài X / Đổi từ khóa / Vẫn tạo (ghi lý do)* | Không tạo trùng im lặng | Có `DuplicateKeywordException`; UI 3 lựa chọn cần xác nhận |
| **S18** Hai người sửa cùng bài | Writer A và B mở cùng phiên bản | Khi B lưu: phát hiện xung đột phiên bản → hiển thị "A đã sửa lúc 09:02" + *So sánh & gộp / Lưu thành bản mới*; không ghi đè im lặng | Không mất công người khác (nỗi sợ của Writer ở brief §1) | Phiên bản & diff có; phát hiện xung đột tại màn sửa = đề xuất |

### 12.5 Nhóm D — Duyệt bài

| Mã | Điều kiện & luồng | Giao diện phải thể hiện | Kết quả | Tình trạng |
|---|---|---|---|---|
| **S19** Duyệt bài (chuẩn) | `Approver` mở bài `needs_review` → đọc, xem Kiểm chất lượng (không còn P0) → *Duyệt* (hoặc kèm ghi chú) | Một primary "Duyệt"; hiện rõ **Duyệt ≠ Xếp lịch** (hai nút riêng); xác nhận hiển thị "bản vN sẽ được duyệt" | `approved`; ghi người duyệt + thời điểm; hiện *Xếp lịch* | Có (action `approve`/`schedule` trong nhánh preview) |
| **S20** Có lỗi P0 chặn duyệt | Quy tắc chất lượng fail (vd thiếu H2, từ khóa vắng, quá ngắn) | Danh sách lỗi theo mức, "Đo được/Ngưỡng", cách sửa; nút Duyệt **khóa** + nêu số lỗi P0 | Chỉ có thể sửa bài hoặc duyệt ngoại lệ | **Đã thấy trên bản xem thử** ("Còn 3 lỗi P0 — chặn duyệt") |
| **S21** Duyệt ngoại lệ | `Approver` chấp nhận rủi ro dù còn lỗi chặn | Bắt buộc nhập **lý do**, xác nhận rõ lỗi nào bị bỏ qua; ghi vào nhật ký kiểm toán; hiển thị huy hiệu "Duyệt ngoại lệ" trên bài từ đây | `approved` kèm cờ ngoại lệ | Dòng chữ "duyệt ngoại lệ với lý do (được ghi log)" **đã thấy**; cần kiểm chứng lưu log thực |
| **S22** Từ chối/yêu cầu sửa | `Approver` bấm *Từ chối* + lý do bắt buộc (có thể kèm chọn đoạn cần sửa) | Lý do hiện cho Writer ở đầu bài; đoạn bị nhắc được đánh dấu; có thể giao lại cho AI "Sửa theo góp ý" (S12) | `needs_revision → drafting` | Có action `reject`; đánh dấu đoạn = P1-1 |
| **S23** Sửa sau khi đã duyệt | Writer/AI sửa bài `approved`/`scheduled` | Cảnh báo trước khi lưu: "Sửa sẽ đưa bài về *Chờ duyệt* và gỡ khỏi lịch"; sau lưu: publication cũ **bị chặn/đánh dấu** vì bản đã duyệt khác bản đã lưu | Bài về `needs_review`; lịch cũ không đăng bản chưa duyệt | Logic liên kết cần `approved_version_id` (PR-C, Dev1) → **UI chưa được hứa** tới khi có |
| **S24** Sai quyền | `Writer` cố mở Duyệt/Website; `Approver` cố sửa credential; URL gõ tay | Trang 403 thân thiện ("Bạn không có quyền… liên hệ Admin"), **không** lộ tên/ID tài nguyên; nút liên quan bị ẩn thay vì chỉ làm mờ | Không thay đổi dữ liệu | Quyền theo vai trò đã định hướng (U5 ở plan); cần ma trận test âm tính |

### 12.6 Nhóm E — Xuất bản (rủi ro cao nhất)

| Mã | Điều kiện & luồng | Giao diện phải thể hiện | Kết quả | Tình trạng |
|---|---|---|---|---|
| **S25** Đăng đúng hạn | `approved` → *Xếp lịch* (giờ VN) → đến hạn → queue gọi CMS → đọc lại | Giờ hiển thị VN (kèm UTC phụ); timeline `queued → publishing → published`; sau cùng link bài + `remote_id`; số bài đăng trên menu cập nhật | `published`, có read-back | Lịch & hàng đợi có (seed); **CMS thật chưa nghiệm thu (staging/UAT: Chưa chạy)** |
| **S26** CMS timeout sau khi gửi bài | Mạng/CMS không phản hồi sau khi request đã đi | Trạng thái `needs_reconcile` màu cảnh báo, giải thích: "Chưa biết bài đã được tạo chưa; hệ thống sẽ đối soát theo mã xuất bản"; **không có nút "Đăng lại"** | Job đối soát chạy (`ReconcilePublicationJob`); không tự tạo bài mới | Backend có (Dev1); UI Đối soát có action `reconcile` |
| **S27** Lỗi xác thực/quyền CMS | 401/403 do credential hết hạn | Lỗi phân loại "xác thực", hành động: "Cập nhật credential ở Website kết nối"; site chuyển `Lỗi`; các bài còn lại của site hiện cảnh báo | `failed` rõ ràng; thử lại chỉ sau khi sửa credential | Phụ thuộc phân loại lỗi (CmsError, PR-D) |
| **S28** Site không hoạt động khi đến hạn | Site `inactive/revoked` đúng lúc đăng | Bài hiện `failed` kèm lý do "Website không hoạt động" (đúng hành vi hiện tại), kèm cách xử lý | `failed`; không đăng | Xác nhận trong code (`PublishArticleJob:64`) |
| **S29** Dừng khẩn cấp (kill switch) | `Manager/Admin` bấm *Tạm dừng lịch đăng* (toàn hệ thống hoặc một site) | Nút **chỉ hiện khi backend có kill switch thật**; xác nhận nêu rõ ảnh hưởng ("n bài sắp đăng sẽ không chạy"); banner đỏ trên mọi trang khi đang tạm dừng; nút *Tiếp tục* có xác nhận | Hàng đợi dừng; bài đang `publishing` cho hoàn tất rồi dừng | Kill switch thuộc 3.6 (Dev1) **chưa xong → Chưa kết nối**: không vẽ nút hoạt động |
| **S30** Đối soát: tìm thấy / không thấy | Job đối soát theo `publication_key` đọc CMS | *Thấy:* chuyển `published` kèm URL (không tạo mới). *Không thấy:* trạng thái "Chưa có bài trên CMS" + lựa chọn do **người** quyết định (đăng lại có kiểm soát bằng cùng `publication_key`) | `published` hoặc chờ quyết định người | Có job/command; màn kết quả đối soát = cần xác nhận |
| **S31** Nghi trùng bài trên CMS | Đối soát thấy 2 bài cùng khóa/tiêu đề | Cảnh báo "Có thể trùng", cho xem cả hai, người chọn giữ/gộp; **không tự xóa** | Quyết định của người, ghi nhật ký | Ý tưởng (cần read-back phong phú từ adapter) |

### 12.7 Nhóm F — Đo lường & tối ưu

| Mã | Điều kiện & luồng | Giao diện phải thể hiện | Kết quả | Tình trạng |
|---|---|---|---|---|
| **S32** Chưa kết nối GSC | Mở Search Console khi chưa cấp quyền | Màn "Kết nối Search Console" thay cho số liệu; mọi KPI = "Chưa có dữ liệu"; không dải số mẫu ở production | Không hiển thị số giả | `DemoMode` ghi quy tắc này; cần kiểm chứng ở chế độ production |
| **S33** Bài tụt hạng → đề xuất cập nhật | Có dữ liệu GSC so sánh kỳ | Danh sách bài giảm, nêu **tương quan, không khẳng định nhân quả**; *Tạo đề xuất cập nhật* → AI soạn bản cập nhật như **phiên bản mới** có diff với bản live | Người duyệt như bài thường | Có `OpportunityFinder/UpdateProposals` ở preview (dữ liệu minh họa); sửa bài live = 5.2 (Dev1) |
| **S34** Xung đột khi cập nhật bài live | Bài live đã bị sửa trực tiếp trên CMS | Cảnh báo "Bản trên CMS khác bản gốc của đề xuất"; so sánh 3 bên (gốc / hiện tại trên CMS / đề xuất); có *Hủy*, *Gộp tay*; có **hoàn tác** sau khi đăng | Không ghi đè nội dung người đã sửa ở CMS | Ý tưởng/Đang phát triển (5.2) — **không vẽ nút hoạt động** |

### 12.8 Nhóm G — AI chạy tự chủ có điều phối + con người can thiệp

Đây là nhóm phản ánh trực tiếp định hướng của bạn. Toàn bộ nhóm này hiện là **Ý tưởng/Đang phát triển** (ARCH-06 orchestrator, ARCH-02 run registry); kịch bản dùng để chốt hành vi mong muốn trước khi Dev1 thiết kế contract.

| Mã | Điều kiện & luồng | Giao diện phải thể hiện | Kết quả |
|---|---|---|---|
| **S35** Chạy chiến dịch end-to-end (chế độ "AI đề xuất, người duyệt") | `Manager/Approver` tạo chiến dịch (website, mục tiêu, chủ đề, số bài, ngân sách AI) → *Bắt đầu* → AI lần lượt: hiểu website → từ khóa → đối thủ → kế hoạch → bài nháp | Thanh giai đoạn (§5.4): mỗi giai đoạn xong dừng ở **cổng duyệt**, hiện báo cáo ngay trên trang tương ứng + thẻ "Chờ bạn"; ngân sách còn lại; ngăn kéo Hoạt động AI cập nhật theo run | AI không sang giai đoạn kế khi cổng chưa duyệt; **Xuất bản luôn do người** |
| **S36** AI cần thêm thông tin giữa chừng (`needs_input`) | AI thiếu đối thủ/tài liệu/xác nhận | Thẻ "AI cần bạn" nổi đầu ngăn kéo và trên trang giai đoạn: câu hỏi cụ thể + ô trả lời/tải tệp/thêm URL ngay tại chỗ; mốc "đã chờ 2 giờ" | Người trả lời → run tiếp tục từ bước đang dừng (không chạy lại từ đầu) |
| **S37** Người tạm dừng / khóa giai đoạn | Người thấy hướng sai → *Tạm dừng* hoặc *Khóa kết quả* một giai đoạn | Giai đoạn `tạm dừng`/`đã khóa`; AI không ghi đè kết quả đã khóa; các giai đoạn sau hiện "chờ giai đoạn trước" | Người sửa tay rồi *Tiếp tục* hoặc *Chạy lại phần còn lại* |
| **S38** Chạy lại một bước (định hướng lại) | Người bấm *Chạy lại* ở một khối AI và nhập yêu cầu mới ("ưu tiên đối thủ nhỏ, bỏ trang tin tức") | Modal: yêu cầu bổ sung + chi phí ước tính + hậu quả ("kế hoạch phía sau phụ thuộc sẽ được đánh dấu *cần xem lại*"); kết quả mới **song song** với cũ, chọn bản giữ | Bản cũ lưu lịch sử; các bước phụ thuộc bị đánh dấu `cần xem lại` chứ không tự xóa |
| **S39** Hủy giữa chừng | Người bấm *Hủy* khi run `running` | Xác nhận: "Phần đã làm được giữ lại; chi phí đã phát sinh X"; run `cancelled` | Dữ liệu một phần giữ với nhãn "chưa hoàn tất" |
| **S40** Vượt ngân sách (`budget_exceeded`) | Chi phí chạm ngưỡng cảnh báo/ngưỡng dừng | Hai mức: **cảnh báo** (banner + `meter--warn`, vẫn chạy) và **dừng** (run `budget_exceeded`, thông báo rõ phần đã xong); *Nâng ngưỡng* chỉ dành `Manager/Admin` kèm lý do | Không phát sinh chi phí ngoài ngưỡng |
| **S41** Run treo/worker lỗi | Không có tiến triển quá thời gian; worker restart | Ngăn kéo hiện "Không có phản hồi từ 09:20" + *Kiểm tra lại / Hủy*; lỗi hệ thống tách khỏi lỗi nội dung; không báo `running` vĩnh viễn | Run `failed` có mã; retry chỉ với thao tác an toàn (idempotent) |
| **S42** Mất kết nối trình duyệt/F5/đóng tab khi đang chạy | Người đóng tab trong lúc run | Mở lại thấy đúng trạng thái run (nguồn sự thật ở server); thông báo "Run vẫn chạy nền", không chạy trùng khi bấm lại cùng nút (khóa theo `correlation_id`) | Không có run trùng |

### 12.9 Nhóm H — Trải nghiệm xuyên suốt

| Mã | Điều kiện | Giao diện phải thể hiện | Tình trạng |
|---|---|---|---|
| **S43** Người mới, chưa có dữ liệu | Hệ thống trống | Empty state có hướng dẫn "Bắt đầu nhanh: thêm website → nhập từ khóa → chạy audit"; trang Lộ trình giải thích luồng; mọi số liệu "Chưa có dữ liệu" | Một phần (có `.empty`); trang Lộ trình = P0-5 |
| **S44** Demo vs production | Cùng mã chạy ở hai môi trường | Demo: dải MINH HỌA + dữ liệu mẫu có kịch bản; Production: không số mẫu, hiện "Chưa kết nối/Chưa có dữ liệu" thật; không trộn website/bài demo với thật | `DemoMode` có; kiểm chứng ở production = Chưa chạy |
| **S45** Nhiều người cùng xem/làm | 2+ người mở chiến dịch | Ngăn kéo hoạt động cho thấy "Minh Trí đang sửa bài này"; khóa mềm ở bài đang sửa; thông báo khi dữ liệu đổi ("Có cập nhật mới — tải lại") | Ý tưởng (cần presence/lock từ Dev1) |
| **S46** Kiểm toán & truy vết | `Manager/Admin` hỏi "ai/AI đã làm gì?" | Dòng thời gian bất biến: actor, vai trò, hành động, đối tượng, lý do, chi phí; lọc theo chiến dịch/website; secret đã che; không sửa/xóa được | Có `AuditRecorder` (log sink); màn xem = P1-3 |

### 12.10 Bản đồ chuyển trạng thái tổng quát (để thiết kế mọi nút hợp lệ)

```
planned ─AI nháp→ drafting ─gửi duyệt→ needs_review ─Duyệt→ approved ─Xếp lịch→ scheduled ─đến hạn→ publishing ─→ published
                      ▲                    │  └Từ chối→ needs_revision ─┐                               │  └→ failed (thử lại sau khi sửa nguyên nhân)
                      └──── sửa bài ◄──────┘                             │                               └→ needs_reconcile ─đối soát→ published | chờ người quyết
   Sửa sau khi approved/scheduled ──────────────► needs_review (và chặn lịch cũ)        [S23]
```
Quy tắc nút: mỗi trạng thái chỉ hiện các hành động hợp lệ; hành động bị cấm vì quyền/chặn thì **ẩn** (quyền) hoặc **disabled kèm lý do** (chặn nghiệp vụ). Không hành động nào nhảy qua trạng thái (không "duyệt và đăng ngay" trong một nút — P3).

### 12.11 Bảng tra "lỗi → giao diện nói gì → hành động an toàn"

| Loại lỗi/trường hợp | Câu giao diện (tinh thần) | Hành động an toàn duy nhất | KHÔNG được làm |
|---|---|---|---|
| Thiếu dữ liệu/nguồn | "Chưa có dữ liệu — cần: <nguồn>" | Cung cấp nguồn / bỏ qua có cảnh báo | Hiện `0`, điền số mẫu |
| AI lỗi/sai schema | "AI không trả kết quả hợp lệ (mã …)" | Chạy lại (xác nhận chi phí) | Thay Mock, tạo bản giả thành công |
| Provider = Mock | Chip `Mock` | Dùng cho demo | Tính vào nghiệm thu |
| Vượt ngân sách | "Đã dừng ở ngưỡng <x>" | Nâng ngưỡng (có quyền+lý do) | Tự chạy tiếp |
| Domain ngoài allowlist/SSRF | "Domain chưa được phép" | Nhờ Admin duyệt domain | Lộ IP/hạ tầng, tự mở rộng domain |
| Credential lỗi | "Xác thực với CMS thất bại" | Cập nhật credential | Hiện lại secret, thử lặp |
| CMS timeout sau khi gửi | "Chưa biết bài đã được tạo chưa — đang đối soát" | Chờ đối soát / xem kết quả | Nút "Đăng lại" |
| Lỗi chất lượng P0 | "Còn n lỗi chặn duyệt" | Sửa, hoặc duyệt ngoại lệ có lý do | Ẩn lỗi, cho duyệt im lặng |
| Xung đột phiên bản | "Người khác đã sửa lúc …" | So sánh & gộp / lưu bản mới | Ghi đè |
| Không đủ quyền | "Bạn không có quyền thực hiện" | Liên hệ Admin | Lộ dữ liệu tài nguyên |
| Run treo | "Không có phản hồi từ …" | Kiểm tra lại/Hủy | Hiện `running` mãi |
| Tính năng chưa nối | Nút disabled + "Chưa kết nối: cần …" | — | Bấm được rồi báo thành công |

### 12.12 Cách dùng bộ kịch bản này

1. **Làm kịch bản nghiệm thu giao diện:** mỗi mã S-xx là một test case (Livewire test cho logic hiển thị + 1 lượt chụp trình duyệt). Báo cáo ghi từng mã ở một trong bốn mức của `AGENTS.md`: *đã viết code → mock đạt → staging → người thật nghiệm thu*, hoặc `Chưa chạy`/`Bị chặn tích hợp`.
2. **Ưu tiên kiểm thử trước:** S13 (AI lỗi không fallback Mock), S26 (timeout không đăng trùng), S20/S21/S23 (cổng duyệt), S06/S16 (bảo mật), S44 (demo vs production). Đây là các rủi ro làm mất niềm tin hoặc gây hậu quả thật.
3. **Phần phụ thuộc Dev1** (S03 `held`, S23, S29, S36–S42, S45) chỉ vẽ ở dạng đặc tả cho tới khi contract được chốt — không dựng giao diện hoạt động trước.
4. **Chưa kiểm chứng:** tôi chưa chạy thử các kịch bản này trên bản xem thử; các ô "Đã thấy" chỉ nghĩa là có dòng chữ/thành phần tương ứng trên màn hình, không phải đã xác minh hành vi.

Nguồn tham khảo bên ngoài: [AgriciDaniel/claude-seo](https://github.com/AgriciDaniel/claude-seo) (README, MIT).
