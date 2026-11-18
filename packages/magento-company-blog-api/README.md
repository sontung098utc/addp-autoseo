# Company_BlogApi (Magento 2 Bridge Module)

Module REST API cầu nối cho hệ thống **Auto SEO & Tự động đăng bài** tương thích với Mageplaza Blog trên Magento 2.

---

## 1. Tính năng
* Cung cấp REST endpoints chuẩn cho AutoSEO tạo bản nháp bài viết, tải ảnh và kích hoạt xuất bản.
* Hỗ trợ lưu trữ và tra soát theo `publication_key` để chống đăng trùng lặp (Idempotency) khi xảy ra sự cố mạng.
* Kiểm tra xác thực chặt chẽ qua Magento 2 Integration ACL (`Company_BlogApi::manage`).
* Tách biệt hoàn toàn, không sửa đổi code core của Magento hay module bên thứ ba (`Mageplaza_Blog`).

---

## 2. Hướng dẫn cài đặt trên Server Magento 2

### Cách 1: Copy thủ công vào `app/code`
1. Sao chép toàn bộ thư mục module này vào thư mục Magento 2 tại:
   `app/code/Company/BlogApi/`
2. Đăng nhập SSH vào server Magento 2 và chạy các lệnh:
   ```bash
   php bin/magento module:enable Company_BlogApi
   php bin/magento setup:upgrade
   php bin/magento setup:di:compile
   php bin/magento cache:clean
   ```

---

## 3. Cấu hình quyền truy cập (Integration OAuth) trên Magento Admin

1. Đăng nhập trang quản trị Magento 2: **System** $\rightarrow$ **Integrations** $\rightarrow$ **Add New Integration**.
2. Đặt tên: `AutoSEO Publisher`.
3. Trong tab **API**, chọn quyền truy cập:
   * **Company Blog API Integration** (hoặc chọn các mục con tương ứng).
4. Nhấn **Save** rồi nhấn **Activate**.
5. Sao chép 4 thông số:
   * Consumer Key
   * Consumer Secret
   * Access Token
   * Access Token Secret
6. Dán 4 thông số trên vào mục cài đặt Website của AutoSEO.

---

## 4. Ảnh đại diện (thumbnail danh sách blog)
* Hệ thống tải ảnh bìa qua `POST /V1/company-blog/media` rồi gửi đường dẫn ở trường `image` của bài. Với Magefan_Blog, ảnh lưu ở `pub/media/magefan_blog/autoseo/` và cột `featured_img` được ghi dạng đường dẫn tương đối so với `pub/media`, có tiền tố `magefan_blog/` (vd `magefan_blog/autoseo/1731_cover.jpg`; đã đối chiếu với các bài có thumbnail trong DB prod), không phải URL tuyệt đối. Bảng `magefan_blog_post` của ADDP không có cột `featured_list_img` (chỉ `featured_img`, `featured_img_alt`); module chỉ ghi `featured_list_img` nếu cột tồn tại. Theme in ảnh danh sách khi `featured_list_img` hoặc `featured_img` khác rỗng.
* **Ảnh bìa phải là JPG/PNG, không WebP**: Magento 2.4.8 (GD2) không resize được WebP mà theme resize `featured_img`. Hệ thống tự chuyển WebP sang JPEG (chất lượng 85, nền trắng) ở phía Laravel trước khi tải lên; module không chuyển đổi.
* Kiểm định dạng cột: `SELECT post_id, featured_img FROM magefan_blog_post WHERE featured_img IS NOT NULL ORDER BY post_id DESC LIMIT 5;`
* Module không lọc nội dung bài (không có strip_tags/purifier), nên không tự bỏ `target`/`rel`.
