# Việc cần làm trên server ADDP (18/11/2026)

Bối cảnh: người dùng tự làm cả deploy (không qua Dev1). Có **hai nơi**:
- **Server ứng dụng Laravel** (`autoseo.addp.vn`): đội AI viết bài, lưu ảnh ở `autoseo.addp.vn/storage/media/...`.
- **Site Magento** (`addp.vn`): blog nhận bài qua module `Company_BlogApi` (mã trong repo: `packages/magento-company-blog-api`).

Nguồn gốc việc: người dùng đăng thử bài "Thuốc hạ đường huyết" (https://addp.vn/blog/thuoc-ha-duong-huyet), thấy lỗi hiển thị, và thử nhiều màn hình trên server.

## A. Server ứng dụng Laravel (autoseo.addp.vn)

PR trên GitHub `sontung098utc/addp-autoseo` (đích `main`, mỗi PR một việc, **không có migration mới**; tổng hợp ở issue #44):

| PR | Việc | Ghi chú |
|---|---|---|
| #39 | Nút Gửi của hộp Xếp lịch/Đổi lịch không bấm được (ô phút `step=5`) | cao |
| #42 | Bài đăng: sửa chữ `">` rò giữa câu (thẻ trích dẫn chèn vào thuộc tính `title` của ghi chú), mục lục lặp, nguồn rác/không trích; **đang có thêm commit** (link nguồn mở tab mới, tooltip, file CSS) | cao |
| #43 | Nhập file kế hoạch: kiểm tra cột AI nhận bằng dữ liệu thật (model server nhận cột sai) | cao |
| #40 | Phiên "Xây kho trí tuệ": hiện chủ đề, lý do lỗi, kết quả trung thực, chống phiên trùng | thường |
| #41 | Thanh loading chỉ hiện cho thao tác người dùng, không hiện khi polling nền | thường |

Các bước sau khi gộp:
1. Gộp PR vào `main` rồi lấy về server (theo cách bạn vẫn deploy).
2. `php artisan config:cache` và `php artisan view:cache` (có đổi view và file tĩnh). `public/js/studio-progress.js` đổi (#41): nếu thanh loading vẫn kiểu cũ thì xóa cache trình duyệt.
3. Cột `articles.planned_at` (từ PR #37, đã gộp trước đó): kiểm đã có trên DB thật bằng `php artisan tinker` → `\Schema::hasColumn('articles','planned_at')`. Chưa có thì `php artisan migrate --force`. Thiếu cột thì ngày dự kiến khi nhập file không lưu được (giao diện có cảnh báo).
4. **Cấu hình AI**: Cấu hình AI → loại việc *Đọc file kế hoạch (Excel/CSV)* trỏ tới model mạnh (model hiện dùng nhận cột kém hơn bản thử ở máy dev; đã thử tốt với `sonnet` qua cầu nối cục bộ, chưa thử model khác).
5. **Phiên "Xây kho trí tuệ" lỗi** (bước 4 "Mỗi nguồn ngoài: tải an toàn, trích chữ, AI rút mục" báo Lỗi ×2, Token 0): chưa biết nguyên nhân gốc vì chưa có log server. Sau khi gộp #40, mở lại một phiên lỗi để đọc **lý do lỗi mới hiển thị**; hoặc xem log Laravel (`storage/logs`). Giả thuyết: hết hạn mức AI (429), route AI `knowledge` chưa cấu hình/model trả JSON sai, chặn tải nguồn (SSRF allowlist), hết giờ tải trang.
6. Phiên cũ đang kẹt (đã thấy một phiên "Viết bài" thuốc hạ đường huyết ở trạng thái "Đang chạy / Tự sửa"): kiểm có kẹt thật không.

## B. Site Magento (addp.vn)

1. **Dán CSS của theme**: file `docs/theme/autoseo-article.css` (phiên điều tra đang soạn, nằm trong PR #42 khi xong). Theme ADDP **chưa có luật CSS nào** cho các lớp `autoseo-*`, nên chữ nguồn/ghi chú/bảng đang bị trình bày mặc định (nguồn/ghi chú 14px dòng cao 19px so với thân bài 17px/31px; tiêu đề cột bảng 20px nhưng ô bảng 14px). Dán vào theme (file gần nhất đã thấy: `.../main-content-style54/blog-styles.css`) hoặc CMS/Custom CSS, rồi `php bin/magento cache:flush` (và deploy nội dung tĩnh nếu theme cần).
   **Thứ tự quan trọng:** tooltip CSS dựa trên thuộc tính markup mới; dán CSS trước hoặc cùng lúc với deploy code để tooltip không hồi quy.
2. **Ảnh đại diện (thumbnail) trang danh sách `/blog`**: bài này **không có thẻ ảnh** ở danh sách (khối `addp-blog-lead` và "Tất cả bài viết") dù trang bài có `og:image` = `https://autoseo.addp.vn/storage/media/2026/11/9AMDyLBVccGgem7elMBD.webp` (cũng là ảnh đầu bài). Nghi trường thumbnail/featured riêng của Magento chưa được điền khi đăng qua API. Việc cần làm: xem bản ghi bài trong DB Magento (bảng bài blog: cột thumbnail/featured_img/image), so với một bài khác có thumbnail; rồi xem module `Company_BlogApi` và payload đăng (`app/Integrations/Magento`, `contracts/`) có gửi/ghi trường đó không; sửa nơi thiếu.
3. **Sửa bài thử đã đăng** (https://addp.vn/blog/thuoc-ha-duong-huyet): bài cũ không tự đổi sau khi deploy. Hoặc đăng lại bản mới do hệ thống dựng (đã hết lỗi `">` và mục lục lặp), hoặc sửa tay trong trang quản trị Magento: xóa khối `<ul class="autoseo-toc">…</ul>` (theme đã tự tạo mục lục, `nav.addp-article-toc`), và sửa thẻ ghi chú [a] ở câu "xét nghiệm Hemoglobin A1c" (thẻ `<sup class="autoseo-note"…>` bị vỡ do một thẻ `<sup class="autoseo-cite"…>` lồng trong thuộc tính `title`).
   Cũng có một mục nguồn thứ 8 **lạc đề** ("Phát động tuần lễ làm mẹ an toàn 2026", mch.moh.gov.vn) và mục nguồn số 4 tiêu đề rác "≥70% ≥50% ≥70%": xóa/sửa tay hoặc đăng lại.
4. **Tiêu đề H2** "Phân loại các nhóm thuốc hạ đường huyết phổ biến" hiện **khung viền chữ nhật** (nhiều khả năng outline focus/hover của theme): kiểm CSS theme.
5. **Link nguồn mở tab mới**: hệ thống sắp phát `target="_blank" rel="nofollow noopener noreferrer"` cho link ngoài (trong PR #42). Kiểm Magento **không loại bỏ** `target`/`rel` khi lưu nội dung bài (bộ lọc nội dung/WYSIWYG), nếu loại thì chỉnh bộ lọc.
6. Store Scope của website ADDP trong ứng dụng phải là `all` (không phải `default`): Magento ADDP không có store code `default` (đã nêu ở issue #35).

## C. Kiểm sau deploy
- Viết một bài mới có ghi chú có kèm trích dẫn nguồn, đăng sang addp.vn, xem HTML đăng ra: không còn `">` giữa câu, chỉ một mục lục (của theme), link nguồn mở tab mới, rê chuột/focus vào [1] [a] thấy nội dung nguồn/ghi chú.
- Nhập một file kế hoạch Excel bằng "Lập kế hoạch → Nhập từ file" (dừng ở bản xem trước nếu chưa muốn tạo) và xem các cột được nhận hợp lý.
- Hộp Xếp lịch: chọn giờ có phút lẻ (vd 14:28) vẫn bấm Gửi được.

## Chưa kiểm (nói thật)
Mọi bản sửa mới kiểm ở máy dev (Docker, SQLite, bản sao DB). **Chưa chạy trên server/staging**. CSS theme chưa thử trên theme thật (chỉ mô phỏng).
