# KẾ HOẠCH GIAO DIỆN DEV2 — 0.5 VÀ CÁC TASK LIÊN QUAN

> Cập nhật 06/11/2026 · Dựa trên: bản Filament thật chạy tại `localhost:8000/admin` (commit `1e24d72`), bộ mockup `design-system/` và `DESIGN_BRIEF.md`, 21 test hiện có (đều xanh).
> Phạm vi file: `app/Filament/**`, `resources/**`, `tests/**`, `docs/**` (đúng allowlist Dev2). Mọi thứ ngoài đó ghi rõ "cần Dev1".

---

## 1. Tóm tắt: khoảng cách giữa mockup và bản thật

Code Filament hiện có là **khung chung** do Dev1 dựng lúc 08:01 05/11 (task 0.4), trước khi bản thiết kế ra đời (10:53–11:07 cùng ngày). Nó không theo thiết kế và có ba lỗ hổng cần chặn trước khi xây tiếp:

| # | Phát hiện (đã kiểm trên bản chạy thật hoặc đọc đúng dòng code) | Mức |
|---|---|---|
| U1 | Trang sửa website đưa **credential dạng chữ thường** vào HTML và vào ô nhập (đã kiểm: mật khẩu mẫu có trong trang) | P0 |
| U2 | `ArticleResource` cho **bất kỳ người dùng nào đổi trạng thái bài thành "Đã duyệt"** bằng ô chọn — qua mặt luồng duyệt (AC01, F-10) | P0 |
| U3 | `PublicationResource` cho tạo/sửa tay lịch đăng, `publication_key`, trạng thái, `cms_post_id`, URL — có thể tự đặt "Đã đăng" hoặc phá khóa chống trùng | P0 |
| U4 | Nút **Xóa website** kéo theo xóa bài, phiên bản và lịch đăng (`cascadeOnDelete`) | P0 |
| U5 | Không có kiểm quyền: `canAccessPanel` chỉ kiểm `is_active`; người viết sửa được website | P1 |
| U6 | **Chưa có màn xem phiên bản bài** (`ArticleVersion`): không đọc được HTML, token, chi phí, lịch sử | P1 |
| U7 | Màn "Tổng quan" trong mockup **không task nào sở hữu**; bản thật là widget mặc định | P2 |
| U8 | Mockup dùng nút gộp **"Duyệt và xếp lịch"**, trái nguyên tắc P3 của chính design brief ("Duyệt" và "Xếp lịch" không chung một nút) | P2 |
| U9 | Mockup: thu hồi website → bài "Tạm giữ, không lỗi". Thực tế `PublishArticleJob` ghi `failed`; enum **không có trạng thái giữ** | P1 |

Bản thật chưa có nhận diện của thiết kế: màu Amber mặc định, 3 mục điều hướng (mockup 6 mục + nhóm), không có banner trạng thái, panel chi tiết hay thao tác "Đổi credential / Thu hồi".

## 2. Nguyên tắc triển khai giao diện

1. **Chức năng và an toàn trước, nhận diện sau.** Mỗi màn làm khi đến lượt task của nó, không dựng lại cả 14 màn trước.
2. **Nhận diện tối thiểu rẻ trước:** màu teal, font, tên thương hiệu, 6 mục điều hướng, badge trạng thái thống nhất, phím tắt tìm kiếm toàn cục. Làm bằng cấu hình panel và một file CSS (không bắt buộc build Vite). *Cần thử trên bản chạy thật để xác nhận.*
3. **Kiểm quyền ở server**, trong Resource (`canViewAny`, `canEdit`, …) dựa trên `isAdmin()/isApprover()` đã có. Khi Dev1 giao Policies thì thay thế, không chờ.
4. **Không giả lập thành công.** Chưa có connector → hiện "Chưa hỗ trợ", không tô xanh.
5. **Nhãn trạng thái tiếng Việt lấy từ MỘT bảng ánh xạ** duy nhất (xem §3.2) dùng chung cho mọi màn.
6. Mỗi thay đổi có **test đỏ trước**. Chạy test: `docker compose exec app php artisan test`.

## 3. Việc làm chung (làm một lần, dùng cho mọi màn)

### 3.1 Nền (gộp vào 0.5)
- Cấu hình panel: tên "SEO Studio", màu `#0F6B5C`, font, nhóm điều hướng (Chiến dịch · Lịch đăng · Khám phá · Phân tích · Cài đặt), `globalSearchKeyBindings`.
- Một `StatusBadge` helper (PHP) trả nhãn + màu cho mọi enum.
- Một `FilamentAuthorizationTest` dùng chung: ma trận vai trò × màn hình.

### 3.2 Bảng ánh xạ nhãn (chốt trước khi code 3.4)

| Enum thật | Nhãn tiếng Việt đề xuất | Ghi chú |
|---|---|---|
| `planned` | Đã lên kế hoạch | |
| `drafting` | Đang viết (AI) | |
| `needs_review` | Chờ duyệt | |
| `rejected` | Cần sửa | mockup gọi "Cần sửa" |
| `approved` | Đã duyệt | chưa xếp lịch |
| `scheduled` | Đã xếp lịch | |
| `publishing` | Đang đăng | |
| `published` | Đã đăng | |
| `failed` | Lỗi | |
| `needs_reconcile` | Chờ đối soát | |
| *(chưa có)* `held` | Tạm giữ | **cần Dev1 quyết** (U9) |

## 4. Kế hoạch từng task có giao diện

### 4.1 Task 0.5 — Panel và quản lý website (10h)

| Bước | Việc | Giờ |
|---|---|---|
| 1 | Nền §3.1: panel, màu, điều hướng, `StatusBadge` | 1 |
| 2 | Factory và fixture 3 site (WP staging, WP giống production, Magento staging), nhãn "dữ liệu mẫu" | 1,5 |
| 3 | **Credential:** không đưa lại vào form; hiện "•••• đã lưu"; chỉ admin thấy; action riêng "Đổi credential" (modal) | 2,5 |
| 4 | Kiểm dữ liệu: chỉ HTTPS, chặn IP nội bộ/metadata (`SafeUrl`), báo trùng `base_url`+`store_scope`; **bỏ chọn tay trạng thái** | 1,5 |
| 5 | Action **Kiểm tra kết nối** gọi connector, hiện kết quả đã che; WordPress chưa có → "Chưa hỗ trợ (1.2)" | 1,5 |
| 6 | Thay **Xóa** bằng **Thu hồi kết nối** (modal: lý do + số bài bị ảnh hưởng, xóa credential, `revoked`); chặn xóa cứng khi còn dữ liệu | 1 |
| 7 | Test: không lộ credential, người viết bị chặn, URL không an toàn bị chặn, xóa bị chặn | 1 |

Theo mockup `websites.html`: danh sách có banner lỗi, lọc WordPress/Magento, panel chi tiết kết nối (slide-over). **Chưa làm trong 0.5:** nhật ký kết nối 90 ngày (cần bảng, Dev1), kiểm tra tự động 6 giờ (scheduler, Dev1).
**Đạt:** L1+L2 (code, test giả lập). F-02 đủ mức staging chỉ sau 1.2 và khi có website thật.

### 4.2 Task 3.4 — Duyệt bài và cấu hình chiến dịch (10h WBS, thực tế ≈16h)

Mockup `article-review`, `campaigns`, `calendar` rộng hơn nhiều so với 10h. Đề xuất cắt theo MoSCoW:

| Mức | Nội dung |
|---|---|
| **Phải có (V1.0)** | (a) Khóa U2/U3: bỏ ô đổi trạng thái tay, luồng chỉ qua action. (b) Resource **ArticleVersion** (xem HTML trong iframe sandbox, metadata, token/chi phí, lịch sử bản). (c) Màn duyệt 2 cột: bài + thông tin SEO/cảnh báo chất lượng. (d) Action: Gửi duyệt · Duyệt · Từ chối (bắt buộc lý do) · Xếp lịch (chỉ khi `approved`), policy theo vai trò. (e) Diff theo đoạn giữa hai bản. (f) Form chiến dịch: tổng bài, bài/ngày, ảnh/bài, giờ đăng + **xem trước lịch (giờ VN và UTC)**. (g) Danh sách lịch đăng: trạng thái + lỗi + nút **Đối soát ngay** (job của Dev1 đã có). |
| **Nên có** | Thẻ "Chất lượng" từ 3.5, chặn Duyệt khi còn P0 (cho phép override có lý do + ghi log) |
| **Để sau (V1.2)** | Duyệt hàng loạt, lịch tuần/tháng, tiến độ và chi phí theo chiến dịch, lưu trữ/nhân bản/xuất, trình soạn "workshop" (sửa từng đoạn, AI viết lại), tô sáng thay đổi con số |

Quyết định cần chốt:
- **Nút gộp "Duyệt và xếp lịch"** (U8): đề xuất giữ hai bước tách như brief; nếu cần tiện thì chỉ cho gộp khi người duyệt đã chọn sẵn ngày giờ và mở rộng thành hộp xác nhận hai dòng.
- **Cần Dev1:** bảng `campaigns` và `approved_version_id` (lô migration A, trước 11/11), trạng thái giữ (U9), nhật ký từng lần thử đăng (nếu muốn "Xem log").
- Gợi ý thêm cho 3.5: một luật "thay đổi con số/số liệu so với bản đã duyệt phải có nguồn", vì mockup đã thể hiện ý này (v1 → v3 đổi học phí, học bổng).

### 4.3 Task 3.5 — Giao diện phần chất lượng
Thẻ chất lượng trong màn duyệt: danh sách `RuleResult` có mức P0/P1/P2, thông báo tiếng Việt, nút "Xem lỗi" cuộn tới đoạn. Giờ nằm trong 3.5 (8h).

### 4.4 Task 2.1 — Nhập từ khóa
Màn danh sách từ khóa theo `keywords.html`: tải CSV, xem trước 20 dòng, nhóm do AI gợi ý, sửa/gộp/tách. Cần bảng (lô B).

### 4.5 Task 2.2 — Kế hoạch nội dung
Lịch theo tuần + kéo thả (Filament không có sẵn; dùng plugin lịch hoặc Livewire + Sortable, **thêm dependency → cần Dev1 duyệt `composer.json`**). Phương án rẻ: V1 đổi ngày bằng hộp thoại, kéo thả để V1.2.

### 4.6 Task 1.3 / 5.1 / 5.4 / 6.4 — Màn phân tích và quản trị
Mockup chưa có: `seo-audit`, `search-console`, `competitors`. Làm mockup ngắn **trước** khi code từng task (xem §5). 6.4: Resource Brand/Department + khóa dữ liệu theo thương hiệu, ít cần thiết kế riêng.

### 4.7 Việc chưa có chủ
- **Tổng quan (dashboard):** đề xuất 3 widget tối thiểu trong 3.4 (bài chờ duyệt, bài lỗi/chờ đối soát, giờ đăng kế tiếp). Chưa tính giờ.
- **Chi phí AI (`ai-usage`):** WBS gán 6.5 cho Dev1, nhưng giao diện chưa rõ ai làm. Cần chốt, kèm câu hỏi VND hay USD.

## 5. Việc thiết kế còn thiếu (ngoài WBS, đề xuất)

| Mã | Việc | Giờ | Cần trước |
|---|---|---|---|
| X1 | Theme tối thiểu (§2.2): ước 3–6h tùy cách làm, thử trước rồi chốt | 3–6 | cùng 0.5 |
| X2 | Mockup `content-plan` + `seo-audit` | 4 | 2.2 / 1.3 |
| X4 | Mockup `search-console`, `competitors`, `ai-usage`, `styleguide` | 6 | 5.1 trở đi |

Giờ này lấy từ dự phòng 15% (64,8h), không tăng 432h.

## 6. Thứ tự làm

`0.5 (khóa U1, U4, U5 trước)` → `3.4 phần Phải có` (cần lô migration A) → `3.5` → `2.1/2.2` → các màn phân tích.
Trong lúc chờ lô A (khoảng 10–11/11): làm X1, ArticleVersion read-only và khóa U2/U3.

## 7. Việc cần hỏi

**Dev1:** lô migration A (`campaigns`, `approved_version_id`, nhật ký kết nối), trạng thái "giữ" khi thu hồi website, chấp thuận `composer.json` nếu cần thư viện diff/lịch, ai sở hữu dashboard và `ai-usage`.
**Quản lý:** VND hay USD, số thương hiệu đồng thời, có cần giao diện tiếng Anh.
**Bạn quyết:** giữ hai nút Duyệt/Xếp lịch riêng; bỏ hẳn nút Xóa website.

## 8. Định nghĩa hoàn thành cho mọi màn
Test đỏ → xanh; không lộ credential/khóa trong HTML; kiểm quyền server-side có test âm tính; ảnh chụp so với mockup; ghi rõ mức bằng chứng đạt (L1/L2/L3/L4) và phần "Chưa chạy"; PR nhỏ, Dev1 review.
