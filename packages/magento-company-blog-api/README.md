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
