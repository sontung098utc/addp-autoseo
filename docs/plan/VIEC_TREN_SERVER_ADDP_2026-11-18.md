# Việc trên server ADDP: trạng thái sau khi sửa ở repo (18/11/2026)

Đã làm ở repo (nhánh fix/m09/ag-q/dev2/bai-dang-chat-luong, test ở máy dev, chưa chạy trên server):
- Gửi ảnh đại diện sang Magento (trường `image`, module ghi `featured_img`/`featured_list_img`), có test mock; module chưa chạy trên Magento thật.
- Link ngoài mở tab mới (`target=_blank`, `rel=nofollow noopener noreferrer`); module Company_BlogApi không lọc nội dung nên không làm mất target/rel.
- Tooltip data-tip/tabindex/aria-label; CSS ở docs/theme/autoseo-article.css.

Bạn tự làm trên server:
1. Deploy code, `php artisan config:cache`, `view:cache`; cập nhật module Company_BlogApi trên Magento rồi `setup:upgrade`, `cache:flush`.
2. Dán docs/theme/autoseo-article.css vào theme hoặc Custom CSS Magento, xóa cache.
3. Kiểm DB: SELECT post_id, featured_img, featured_list_img FROM magefan_blog_post ORDER BY post_id DESC LIMIT 5; so định dạng với bài có ảnh sẵn.
4. Đăng lại bài thử "Thuốc hạ đường huyết" (bài cũ không tự đổi); xem mã nguồn trang kiểm target/rel, thumbnail ở /blog.
5. Kiểm viền H2 trong CSS theme; Store Scope website ADDP phải là `all`.
