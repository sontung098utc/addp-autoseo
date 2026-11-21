# Thiết kế chi tiết thư viện ảnh, bước 1 (21/11/2026)

Quyết định đã chốt (người dùng, 21/11): dùng mặc định (ảnh ít nhưng vẫn dry-run; cây thư mục theo chủ đề, website là bộ lọc; dedupe toàn cục theo sha256); **giữ đường dẫn cũ cho ảnh Magento**; không được ảnh hưởng việc đăng bài.

## Phát hiện về CMS
- Ảnh bìa: cả Magento và WordPress upload bản sao sang CMS (nhớ ở `metadata.magento_media` / `metadata.wp_media`), không phụ thuộc URL của ta.
- Ảnh trong thân bài: WordPress được rewrite sang URL WordPress khi đăng; **Magento không rewrite**, `src` là URL của ta (`MediaAsset::url`). Vì vậy `file_path` của mọi ảnh đã gắn vào bài không được đổi/xóa ở các bước sau (hoặc phải có redirect). Đề xuất riêng: rewrite ảnh thân bài Magento (ngoài phạm vi).
- Form Filament `CreateMediaAsset` không qua `MediaService` (không dedupe/nén/thumbnail): xử lý ở bước UI.

## Schema bước 1 (chỉ bổ sung)
- `media_assets`: `kind` (default `photo`), `status` (default `ready`), `folder_id` -> `media_collections` (null on delete), `usage_count`, `slug`. Giữ nguyên `tags` JSON, `file_path`, `disk`, `thumbnail_path`.
- Bảng mới: `media_collections` (cây, unique parent+slug), `media_collection_asset`, `media_tags`, `media_asset_tag`.
- Backfill idempotent: `usage_count`, `kind=ai_generated` khi `metadata.ai_generated`, tag từ JSON (bỏ qua JSON hỏng/trống).
- `status` mặc định `ready` để mọi luồng hiện tại (đăng bài, ảnh AI, chèn ảnh) không đổi hành vi.

## File (theo allowlist AGENTS.md; PR độc lập cho DB/Models)
- `database/migrations/2026_11_21_100000_extend_media_assets_with_kind_status_folders_tags.php`
- `app/Models/{MediaAsset,MediaCollection,MediaTag}.php`
- `tests/Feature/MediaLibraryRestructureStep1Test.php`

## Kiểm chứng
Viết code + test giả lập (SQLite :memory:, Docker). Chưa chạy trên MySQL/staging, chưa có người nghiệm thu. Bộ test đầy đủ: 20 lỗi có sẵn, trùng khớp trước/sau thay đổi.
