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

## Dữ kiện đã xác nhận trên site (bổ sung)
- CSS theme thật: `app/design/frontend/Codazon/unlimited/child/web/css/addp-article.css` (bản min ở `pub/static/frontend/Codazon/unlimited_child/{vi_VN,en_US}/css/addp-article.min.css`); thân bài trong `.blog-post-view .addp-article-copy`. Không file CSS nào hiện có chứa `autoseo`. Đặt luật ở đó, không dùng Custom CSS admin.
- Cloudflare giữ bản đầu của CSS tĩnh: phải đổi tên file/thêm `?v=` hoặc purge cache.
- Khung viền H2 là thiết kế theme (`:is(h2,h3):target { outline }`, hiện khi URL có #id), không sửa.
- `target`/`rel`: không mã nào loại bỏ (Magefan chỉ lọc directive `{{…}}`, Company_BlogApi không sanitize); chưa thử ghi thật.
- Thumbnail: `list-modern.phtml` chỉ in ảnh khi `featured_list_img` hoặc `featured_img` khác rỗng. Hệ thống nay gửi `image` = đường dẫn tương đối so với `media/magefan_blog/` do module trả về (chưa kiểm giá trị thật; kiểm bằng SQL ở README module).
- H2 id hiện có (theme dùng cho mục lục, giữ định dạng): dinh-nghia-va-chi-dinh, phan-loai-nhom-thuoc, su-khac-biet-cac-tup, bang-tong-hop, tac-dung-phu-va-an-toan, nguyen-tac-tuan-thu, ket-hop-loi-song.
- Mục "dán CSS vào blog-styles.css / Custom CSS" ở bản trước đã thay bằng đường dẫn trên.

## Đã làm trên prod addp.vn (18/11/2026 ~15:39, theo báo cáo phiên làm trực tiếp trên site)
- Module Company_BlogApi: `MediaManagement` lưu ảnh ở `pub/media/magefan_blog/autoseo/` và trả `file` = `magefan_blog/autoseo/<tên>`; `PostManagement` ghi `featured_img`. Bảng `magefan_blog_post` không có cột `featured_list_img` (chỉ `featured_img`, `featured_img_alt`); theme dùng `getFeaturedListImg() ?: getFeaturedImg()` nên đủ.
- CSS theme: file riêng `addp-autoseo-article.css` đăng ký trong `Magefan_Blog/layout/blog_post_view.xml`, deploy tĩnh vi_VN/en_US; trang thật nạp được. Bản chuẩn trong repo: `docs/theme/autoseo-article.css`.
- Bài post_id 163: xóa `autoseo-toc`, sửa `sup` ghi chú [a], bỏ nguồn lạc đề, đổi tiêu đề nguồn 4, `target=_blank` cho 7 link, ảnh đại diện chuyển JPG. Sao lưu: `~/domains/addp.vn/deploy-backup-autoseo-20261118153951` (kèm `restore-post163.sql`).

## Mới trong repo (khớp prod), còn phải deploy
- Ảnh bìa gửi sang Magento phải là JPG/PNG (GD2 của Magento không resize được WebP): Laravel tự chuyển WebP sang JPEG (chất lượng 85, nền trắng) trước khi tải; WebP hỏng thì bỏ ảnh bìa, bài vẫn đăng.
- Bài MỚI cần deploy code Laravel (PR #42) mới có markup mới (data-tip, target=_blank, lớp `autoseo-refs__title`/`autoseo-notes__title`, không còn mục lục); CSS prod đã có luật cho hai lớp tiêu đề này.
- Chưa kiểm: bài mới đăng thật qua API sau deploy.
