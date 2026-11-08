# Mẫu giao diện (chưa phải tính năng)

`auto-seo-ui-preview.html` là **bản vẽ tĩnh bấm được** để duyệt thiết kế và thử người dùng
(xem `../USABILITY_TEST_SCRIPT.md`). Dữ liệu và nút là **minh họa**, chưa nối backend.

## Cách xem
File dùng CSS của bộ Design System (`design-system/assets/css/*.css`), **nằm ngoài repo này**
(thư mục anh em của repo: `../../../../design-system/`). Phục vụ qua máy chủ tĩnh đặt gốc ở thư mục
chứa cả hai thư mục, ví dụ:

```bash
cd <thư-mục-chứa-repo-và-design-system>
python3 -m http.server 8777 --bind 127.0.0.1
# mở http://127.0.0.1:8777/<tên-repo>/docs/seo-agent/mockups/auto-seo-ui-preview.html
```
Nếu không có `design-system/`, trang vẫn mở được nhưng mất định dạng.
