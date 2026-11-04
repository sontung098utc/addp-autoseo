# QUY TẮC LÀM VIỆC DÀNH CHO KỸ SƯ VÀ AI AGENT (AGENTS.md)
*Dự án: Phần mềm Auto SEO & Tự động đăng bài (addp-autoseo)*

Tài liệu này xác lập quy chuẩn kỹ thuật, giới hạn bảo mật và quy trình phát triển cho toàn bộ nhân sự (Dev1, Dev2) và các trợ lý AI tham gia xây dựng hệ thống.

---

## 1. NGUYÊN TẮC CỐT LÕI (CORE PRINCIPLES)

1. **Bảo mật tuyệt đối (Security First):**
   - Không bao giờ commit plain text passwords, API keys, OAuth secrets, database dumps, `.env` vào repository hay ghi vào spreadsheets.
   - Toàn bộ secret kết nối (WordPress Application Passwords, Magento OAuth secrets, Google API tokens) phải được mã hóa trước khi lưu trữ trong cơ sở dữ liệu.
   - Phòng chống SSRF triệt để: Crawler và bộ tải ảnh chỉ được truy cập các domain công ty thuộc allowlist đã được phê duyệt. Chặn toàn bộ IP nội bộ/loopback (`127.0.0.1`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.169.254` metadata).
   - Lọc sạch mã HTML đầu ra của AI trước khi hiển thị (XSS prevention) và trước khi bắn sang CMS.

2. **Trung thực về bằng chứng (Honest Evidence Contract):**
   - Nghiêm cấm bịa đặt kết quả kiểm thử, báo cáo hoàn thành giả định, hoặc tự ý đánh dấu "Đã nghiệm thu" khi chưa chạy thực tế.
   - Mọi tuyên bố hoàn thành task phải có 4 mức kiểm chứng:
     $$\text{Đã viết code} \longrightarrow \text{Test giả lập / Mock đạt} \longrightarrow \text{Tích hợp môi trường staging} \longrightarrow \text{Người thật nghiệm thu}$$
   - Nếu thiếu môi trường hoặc thiếu tài khoản kết nối, phải báo cáo trạng thái `Chưa chạy` hoặc `Bị chặn tích hợp`, không điền giá trị 0 hoặc mock để giả thành công.

3. **Tách biệt AI nội dung và Thực thi hệ thống:**
   - AI sinh nội dung (LLM) chỉ trả về dữ liệu có cấu trúc (JSON / Structured Schema).
   - Tuyệt đối không cho phép nội dung văn bản từ web crawl hay tài liệu bên ngoài điều khiển các công cụ thực thi hệ thống (chống Prompt Injection).

4. **Bảo toàn trạng thái xuất bản & Chống đăng trùng (Idempotency):**
   - Mọi lượt xuất bản phải có `publication_key` duy nhất.
   - Khi xảy ra lỗi mạng hoặc timeout từ CMS sau khi gửi bài, job phải chuyển sang trạng thái `needs_reconcile` để đối soát theo `publication_key`. Không được tự động retry tạo thêm bài mới.

---

## 2. PHÂN VAI & PHẠM VI TRÁCH NHIỆM

* **Dev1 (Chủ nhiệm Hạ tầng, Backend Core & Magento):**
  - Môi trường Docker, HTTPS, CI/CD, database migrations, Redis Queue & Scheduler.
  - Module kết nối Magento 2 (`Company_BlogApi`), bảo mật credential, backup/restore.
  - Reviewer cho các mã việc của Dev2.
* **Dev2 (Chủ nhiệm Giao diện Filament, WordPress & AI):**
  - Giao diện quản trị Filament bằng tiếng Việt: Quản lý website, duyệt bài, chiến dịch, lịch xuất bản.
  - Module kết nối WordPress REST API.
  - Thiết kế prompt AI, đo token/chi phí, bộ kiểm tra chất lượng SEO cơ bản.
  - Reviewer cho các mã việc của Dev1.

---

## 3. PHẠM VI THƯ MỤC & FILE (CODE ALLOWLIST)

Mỗi tính năng chỉ được chỉnh sửa trong thư mục đã được phân quyền:
* **Hạ tầng & Cấu hình:** `docker/`, `docker-compose.yml`, `config/`, `.github/workflows/` (Chủ nhiệm: Dev1).
* **Database & Contracts:** `database/migrations/`, `contracts/`, `app/Models/` (Cần PR độc lập).
* **Core & CMS Integrations:**
  * `app/Integrations/WordPress/` (Chủ nhiệm: Dev2).
  * `app/Integrations/Magento/`, `packages/magento-company-blog-api/` (Chủ nhiệm: Dev1).
* **AI & Content Pipeline:** `app/Services/Ai/`, `app/Services/Content/`, `resources/prompts/` (Phối hợp).
* **Filament Admin Panel:** `app/Filament/` (Chủ nhiệm: Dev2).
* **Queue & Scheduler:** `app/Jobs/`, `app/Console/` (Chủ nhiệm: Dev1).

---

## 4. QUY TRÌNH GIAO VIỆC & MỞ PR

1. **Khởi tạo:** Nhận task từ `TASKS.md`, tạo nhánh đúng cú pháp `<loại>/<Mxx>/<mã>/<dev>/<slug>`.
2. **Triển khai:** Chỉ sửa các file trong allowlist của task. Viết Unit/Feature test tương ứng.
3. **Mở Pull Request:**
   - PR phải ghi rõ: Mã task, mục tiêu, các file thay đổi, bằng chứng log test / CI run URL, rủi ro và các điểm chưa kiểm thử.
   - Dev còn lại bắt buộc review chéo code và test trước khi merge vào `develop`.
   - Tuyệt đối không commit trực tiếp lên `main` hoặc `develop`.
