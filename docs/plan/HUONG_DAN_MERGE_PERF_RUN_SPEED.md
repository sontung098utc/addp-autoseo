# Hướng dẫn merge và deploy nhánh `perf/m09/ag-s27/dev2/run-speed`

*Cho Dev1 (hoặc người có quyền root trên server). Soạn 17/11/2026. Server: `/home/addp/domains/autoseo.addp.vn/public_html`, PHP 8.2 (`/usr/local/php82/bin/php82`), MySQL, `main` đang ở `df0686f`.*

## 1. Nhánh này làm gì (12 commit trên `df0686f`)
Không đổi giao diện (file `app/Filament`, `resources/views`, `public` không đổi), không đổi logic xuất bản của Dev1, không đổi `composer.json`/`composer.lock`.

| Nhóm | Nội dung | Vì sao cần trên server |
|---|---|---|
| **Hàng đợi** | Migration `2026_11_17_090000_create_queue_tables` (tạo `jobs`, `failed_jobs`, chạy lại an toàn) + lịch `queue:work --stop-when-empty --max-time=50` mỗi phút (chỉ khi `QUEUE_CONNECTION` khác `sync`) | Server dùng `QUEUE_CONNECTION=database` nhưng **chưa có bảng `jobs`**: log 16/11 16:55 "Table 'addp_autoseo.jobs' doesn't exist", lượt đăng #1 (ADDP) kẹt ở `publishing` |
| **Trang chậm** | `KnowledgeDedupe` (tải mục một lần, bỏ qua cặp chắc chắn không giống), nhớ cảnh báo trùng/mâu thuẫn theo dấu vân tay kho, `KnowledgeConflicts::all()` tính trong bộ nhớ, nhớ `hasTable/hasColumn` 20 giây (`MemoizedSchemaBuilder`) | Trang `/admin/tri-thuc-tat-ca` **trả 500** trên server ("Maximum execution time of 90 seconds exceeded" tại `KnowledgeDedupe.php:43`); các trang Kho trí tuệ, Pipeline, Đội & Vai trò chậm do hàng trăm truy vấn |
| **Tìm web** | `GeminiSearchProvider`, `CodexBridgeSearchProvider`, `CombinedSearchProvider`; resolver: có `TAVILY_API_KEY` thì dùng Tavily trước, chỉ khi Tavily lỗi/hết hạn mức mới chuyển sang AI tự tìm (cầu nối Claude nếu có, không thì Gemini) | Bài chạy trên server không có nguồn web; nay có Tavily, có dự phòng AI |
| **Chạy bài nhanh hơn** | Tìm web song song + ghi nhớ trong phiên, sửa theo đoạn (`SectionSplice`), gộp nguồn trùng | Giảm thời gian mỗi bài |
| **Triển khai** | `BackgroundRun` ghi lý do khi không khởi chạy được tiến trình nền; `AGENT_PHP_BINARY`; `TrustedProxiesServiceProvider` (mặc định tắt, `TRUSTED_PROXIES`); bỏ `bootstrap/cache/packages.php|services.php` khỏi git | Tiến trình nền phải dùng đúng PHP 8.2; tránh xung đột cache sinh tự động khi merge |

## 2. Việc cần biết trước khi merge
1. **Quyền sở hữu file trên server không đồng nhất.** Khoảng 926 file/thư mục trong cây mã (`app/`, `tests/`, `database/migrations/`, `resources/`...) và 105 mục trong `.git` (kể cả `.git/refs/heads/main`, `FETCH_HEAD`) thuộc `root`, trong khi web chạy bằng `addp`. Vì vậy **tài khoản `addp` không merge được** (`unable to create file ... Permission denied`). Hãy merge bằng root, hoặc đổi chủ trước (xem mục 3, bước 0).
2. **Hai file cache đang bị sửa tay trên server** (`bootstrap/cache/packages.php`, `services.php` hiện là bản sinh tự động khác bản trong git). Nhánh này bỏ chúng khỏi git và thêm `/bootstrap/cache/*.php` vào `.gitignore`, nên merge sẽ báo xung đột "local changes would be overwritten": sao lưu rồi `git checkout --` hai file đó trước khi merge, sau đó `package:discover` để sinh lại.
3. **Nhánh gốc của nhánh này là `b963662`, `main` trên server là `df0686f`** (gồm các commit merge của Dev1). Nội dung hai bên giống nhau từng byte nên `git merge` sạch, nhưng **không thể fast-forward**: sẽ có một commit merge.
4. Server đã có sẵn `TAVILY_API_KEY` trong `.env` (thêm 17/11, không nằm trong repo) và `config:cache` đã được chạy. Mọi thay đổi `.env`/config sau này cần `config:cache` lại.

## 3. Các bước (chạy bằng root hoặc người sở hữu cây mã)
```bash
cd /home/addp/domains/autoseo.addp.vn/public_html
PHP=/usr/local/php82/bin/php82
B=/home/addp/domains/autoseo.addp.vn/backups/$(date +%Y%m%d-%H%M)-truoc-merge-perf && mkdir -p $B && chmod 700 $B

# 0. Sao lưu (DB, .env, cache). Đã có bản sáng 17/11 tại backups/20261117-0819-truoc-so-sanh/ nhưng nên sao lại ngay trước khi merge.
mysqldump --single-transaction addp_autoseo | gzip > $B/db.sql.gz     # dùng tài khoản MySQL của ứng dụng
cp -p .env $B/env.bak; cp -p bootstrap/cache/*.php $B/ 2>/dev/null; git rev-parse HEAD > $B/commit-truoc.txt

# 1. Lấy nhánh và kiểm tra trước khi merge
git fetch origin
git log --oneline main..origin/perf/m09/ag-s27/dev2/run-speed | wc -l        # kỳ vọng: 12 (hoặc hơn nếu có commit mới)
git diff --stat main...origin/perf/m09/ag-s27/dev2/run-speed -- composer.json composer.lock   # kỳ vọng: trống

# 2. Hai file cache sinh tự động: bỏ thay đổi cục bộ (đã sao lưu ở bước 0), rồi merge
git checkout -- bootstrap/cache/packages.php bootstrap/cache/services.php
git merge --no-ff origin/perf/m09/ag-s27/dev2/run-speed -m "Merge perf/m09/ag-s27/dev2/run-speed (hàng đợi, tối ưu trang, tìm web Tavily + AI)"

# 3. Sinh lại cache gói, xem SQL trước khi chạy migration
$PHP artisan package:discover
$PHP artisan migrate --pretend       # chỉ nên có: create table `jobs`, `failed_jobs` (bỏ qua nếu đã tồn tại)
$PHP artisan migrate --force

# 4. Cấu hình (thêm vào .env nếu chưa có), rồi làm mới cache
#    AGENT_PHP_BINARY=/usr/local/php82/bin/php82      # BẮT BUỘC cho tiến trình nền chạy bài (nếu không có, có thể dùng nhầm php CLI khác)
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache

# 5. Sau khi merge nếu thư mục mới tạo thuộc root mà web (addp) cần ghi: giữ đúng chủ như thư mục cha
#    (storage/ và bootstrap/cache/ phải ghi được bởi addp)
```
Không cần `composer install`, không có thay đổi `package.json`/Vite.

## 4. Kiểm tra sau khi merge
```bash
$PHP artisan migrate:status | tail -3                # migration 2026_11_17_090000_create_queue_tables = Ran
$PHP artisan schedule:list | grep -E "queue:work|publish-due|reconcile"   # queue:work mỗi phút
$PHP artisan tinker --execute='echo json_encode((new App\Services\Search\WebSearchResolver)->availability(), JSON_UNESCAPED_UNICODE);'
#   kỳ vọng: ok=true, label "Tavily, dự phòng AI tự tìm (Gemini)"
```
- Cron `* * * * * ... artisan schedule:run` đã chạy sẵn (campaign #1 có `last_tick` 17/11 08:15), nên **không cần supervisor**. Worker chạy tối đa 50 giây mỗi phút rồi thoát.
- Mở `/admin/tri-thuc-tat-ca`: kỳ vọng HTTP 200 (trước đó 500); và 10 trang chính vẫn 200.
- Đợi 2–3 phút rồi xem `select count(*) from jobs` (hàng đợi đang được xử lý, không tăng mãi) và `storage/logs/laravel.log` không có lỗi mới.
- Tìm web: chạy thử một bài ngắn từ trang Đội AI, xem bước tìm nguồn ghi nhà cung cấp là Tavily.

## 5. Lượt đăng #1 đang kẹt (ADDP, Magento) — quyết định của người, không tự sửa
Publication id 1 (key `pub-2-a3-v1`, bài #3 "Cách trị bệnh tiểu đường tại nhà") ở trạng thái `publishing`, `attempt_count = 0` từ 16/11 16:55: lệnh đưa job vào hàng đợi đã lỗi vì thiếu bảng `jobs`, tức là **yêu cầu chưa từng tới CMS**. Hệ thống đối soát chỉ xử lý trạng thái `needs_reconcile` nên sẽ không tự đụng vào lượt này.
1. Kiểm trên Magento/ADDP xem có bài nào gắn khóa `pub-2-a3-v1` chưa (theo quy ước `publication_key`, tuyệt đối không tạo thêm bài mới trước khi đối soát).
2. Nếu chưa có: đặt `status='needs_reconcile'` để `ReconcilePublicationJob` xác nhận và chuyển `failed` ("an toàn để lên lịch lại"), rồi lên lịch lại từ giao diện. Nếu đã có: cập nhật `cms_post_id`/`published_url` và `status='published'`.
3. Bài #3 là nội dung y tế: chỉ đăng khi người có chuyên môn đã duyệt.

## 6. Quay lui
```bash
git reset --hard $(cat $B/commit-truoc.txt)      # về df0686f
cp -p $B/packages.php $B/services.php bootstrap/cache/ ; cp -p $B/config.php bootstrap/cache/ 2>/dev/null
$PHP artisan config:cache
```
Bảng `jobs`/`failed_jobs` không cần xóa (migration không có `down`; các bảng rỗng vô hại, và `QUEUE_CONNECTION=database` vẫn cần chúng). Tắt worker = quay lui mã (lịch `queue:work` nằm trong `routes/console.php`).

## 7. Điểm còn mở (trung thực)
- **Gemini tìm web (Google Search grounding) đang bị 429** với khóa hiện có (cả máy dev lẫn server): khóa miễn phí không có hạn mức công cụ này. Dự phòng AI chỉ hoạt động khi bật thanh toán cho khóa Gemini hoặc có cầu nối Claude/Codex; hiện chỉ có Tavily.
- Bộ test đầy đủ: 49 lỗi đã có từ trước (baseline), không lỗi mới; 2 test `QualityReviewScreenTest` không ổn định ngay cả trên `df0686f`. Chưa chạy bộ test này trên staging/server.
- Đã thử rồi bỏ: nhớ bảng gán model theo vai trò (làm 6 test lỗi). Trang Bài viết vẫn nhiều truy vấn (tính "mức sẵn sàng" từng bài).
- Chưa kiểm trên server: tốc độ thật của các trang sau khi merge, chạy bài end-to-end có Tavily.
