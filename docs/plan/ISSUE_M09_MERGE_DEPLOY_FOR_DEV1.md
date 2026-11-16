@sontung098utc (Dev1) — **Gửi từ Dev2 · Nhánh `feat/m09/ag-s27/dev2/article-workspace` @ `9fe4e34` · Cần Dev1 merge và triển khai lên `autoseo.addp.vn`**

> Tiêu đề issue đề xuất: **[M09] Merge nhánh article-workspace vào develop/main và triển khai lên server (kèm 28 migration)**
> Nhãn đề xuất: `deploy`, `migration`, `m09`, `cần-dev1`

## Tóm tắt
Nhánh trên (đã push) gom toàn bộ phần M09: đội AI viết bài, chiến dịch chạy song song, kho Trí tuệ, từ khóa/SERP/Search Console. Mong Dev1 merge theo đúng thứ tự dưới và xác nhận chạy đúng trên server. **Dev2 không thể tự khẳng định bản này chạy đúng như máy Dev2** (xem mục Mức kiểm chứng).

*Ngày 16/11/2026. Người gửi: Dev2. Chưa ai nghiệm thu bản này ngoài máy Dev2.*

## 1. Cái gì được bàn giao
- Nhánh: `feat/m09/ag-s27/dev2/article-workspace`, commit cuối `9fe4e34`, đã push lên GitHub.
- Hơn `develop` **322 commit**, 812 file, khoảng 81.800 dòng thêm, **28 migration mới**. `origin/develop` nằm gọn trong nhánh này nên merge là fast-forward, không xung đột. `origin/main` hơn 2 commit merge (PR-D), nội dung đã có trong `develop`.
- Nội dung chính: đội AI viết bài (Agent Run), chiến dịch chạy song song, kho Trí tuệ và điều phối nhiều kho, từ khóa/SERP/Search Console, đề xuất chiến dịch, cầu nối Claude.
- Trong nhánh có các file `database/preview.sqlite*`: **không** thuộc nhánh (chỉ nằm ở máy Dev2, không được đưa vào repo).

## 2. Mức kiểm chứng (trung thực theo AGENTS.md)
| Mức | Trạng thái |
|---|---|
| Đã viết code | Có |
| Test giả lập / mock | Đạt trên **SQLite `:memory:`** trong container Dev2: 1.901 đạt, **49 lỗi có sẵn từ trước** (chủ yếu publisher / CI), không lỗi mới |
| Tích hợp staging | **Chưa chạy.** Chưa chạy trên MySQL, chưa chạy trên server |
| Người thật nghiệm thu | **Chưa** |

CI của repo dùng **MySQL 8 + PHP 8.2**. Test của Dev2 chạy trên SQLite, nên cần xem CI chạy MySQL báo gì trước khi merge vào `main`.

## 3. Việc cần làm trước khi merge vào `main` và deploy
1. **Mở PR vào `develop`**, chạy CI (MySQL), xem lỗi. Dev2 đã đối chiếu: trên SQLite, tập test lỗi trước và sau thay đổi gần nhất giống nhau (49). Trên MySQL chưa biết, nên so CI với `develop` trước khi kết luận.
2. **Sao lưu DB server** (`autoseo.addp.vn`) trước khi chạy migration.
3. **Chạy `php artisan migrate --pretend`** trên bản sao DB server để xem SQL, rồi mới `migrate` thật. 28 migration mới (từ `2026_11_10_100001` đến `2026_11_13_000001`): các bảng `agent_*`, `ai_providers`, `brains`, `brain_sources`, `knowledge_*`, `keywords_*`, `competitors_*`, `audit_suggestions`, cột mới trên `campaigns`, `articles`, `article_versions`. Chưa kiểm trên MySQL.
4. **Biến môi trường mới** (xem `.env.example`), đặt trên server theo nhu cầu: `AI_PRIVATE_BASE_URL_ALLOWLIST`, `AGENT_IMAGES_PARALLEL`, `AGENT_IMAGES_MAX_WIDTH`, `AGENT_IMAGES_QUALITY`, `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD`, `SERP_AUTOCOMPLETE`, `GSC_SERVICE_ACCOUNT_JSON`, `GSC_PROPERTY_TYPE`, `GSC_PROPERTIES`, `TRENDS_CONTACT`. Không commit giá trị thật.
5. **Cấu hình mới:** `config/agent.php`, `ai_models.php`, `ai_rate.php`, `gsc.php`, `keywords.php`, `knowledge.php`, `quality.php`, `search.php`, `studio.php`, `trusted_sources.php`. Sau khi deploy chạy `php artisan config:clear` (và `optimize` nếu server đang cache).
6. **Scheduler và hàng đợi.** `routes/console.php` có các nhịp mới: `agent:tick` (15 phút), `autopilot:tick` (30 phút), `agent:reap-stale` (10 phút), `trends:snapshot` (03:30). Server cần chạy `schedule:run` mỗi phút. Bài viết chạy nền bằng lệnh `agent:run-article` (tiến trình riêng, **không** qua Redis queue; việc chuyển sang hàng đợi thật là AG-B10 chưa làm). Cần xác nhận server cho phép chạy tiến trình nền kiểu này.

## 4. Dữ liệu không có trong git (DB server sẽ trống)
- Nhà cung cấp AI, Trí tuệ, mục kho và nguồn tin cậy, hồ sơ vai trò Agent Studio, chiến dịch, website: tất cả là **dữ liệu trong DB**, không nằm trong migration. DB server không có những thứ này nếu chưa nhập.
- Có lệnh dựng lại một phần: `agent:claude-setup` (nhà cung cấp Claude), `brains:install-health` (14 mẫu Trí tuệ sức khỏe), `brain:build-domain {id}` (xây kho theo lĩnh vực). Chưa thử trên server.
- **Nhà cung cấp AI #4 "Claude CLI (cầu nối)"** trỏ vào `localhost:8771` trên máy Dev2 (dịch vụ `claude-bridge`, script `scripts/claude_bridge.py`). **Server không có cầu nối này.** Muốn đội AI viết bài trên server phải chọn: (a) dựng cầu nối trên server (đăng nhập tài khoản Claude chạy bài, không dùng tài khoản code), hoặc (b) cấu hình nhà cung cấp AI khác trong màn hình quản trị.
- Mục kho y tế vẫn cần dược sĩ duyệt; không tự duyệt hàng loạt.

## 5. Kiểm tra sau deploy (bắt buộc, người thật làm)
1. Đăng nhập `/admin`, mở các trang Chiến dịch, Kế hoạch, Từ khóa, Trí tuệ, Agent Studio xem có lỗi 500 không.
2. Giao **một** bài thử qua đội AI trên website thử, kiểm tra từng bước chạy, nội dung, chú thích nguồn.
3. Chỉ sau khi bài thử đạt mới bật chiến dịch thật. **Không bật `settings.concurrency` cao** khi chưa biết hạn mức nhà cung cấp AI.

## 6. Rủi ro đã biết
- 49 lỗi test có sẵn (chưa phân loại là do môi trường hay do code).
- Chưa chạy trên MySQL; chưa chạy trên server.
- Chạy một bài mất khoảng 45 phút qua cầu nối Claude (mỗi lượt gọi 3–5 phút, nhiều vòng sửa).
- Dịch vụ ảnh có thể trả 429 (hết hạn ngạch); bài vẫn hoàn tất nhưng không có ảnh.
- Một bản sao lưu DB cục bộ nằm ở `database/preview.sqlite.bak-*` trên máy Dev2, không liên quan server.

## 7. Liên hệ
Chi tiết kỹ thuật: `docs/seo-agent/BAN_GIAO_2026-11-15.md` (file này chưa nằm trong git, sẽ gửi kèm khi cần).

## Checklist cho Dev1
- [ ] Mở PR vào `develop`, chạy CI (MySQL 8, PHP 8.2), ghi lại kết quả vào PR
- [ ] So lỗi CI với `develop` (Dev2 đo 49 lỗi có sẵn trên SQLite)
- [ ] Sao lưu DB server
- [ ] `migrate --pretend` trên bản sao DB, rồi `migrate` thật; ghi lại migration lỗi (nếu có)
- [ ] Đặt biến môi trường mới, `config:clear`
- [ ] Bật `schedule:run` mỗi phút; xác nhận cho phép tiến trình nền `agent:run-article`
- [ ] Quyết định nhà cung cấp AI trên server (dựng cầu nối Claude hoặc nhà cung cấp khác)
- [ ] Chạy thử **một** bài, người thật nghiệm thu, rồi mới bật chiến dịch thật
- [ ] Merge `develop` → `main` sau khi các mục trên đạt
