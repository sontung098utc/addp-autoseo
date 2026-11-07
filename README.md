# Hệ thống Auto SEO & Tự động đăng bài (addp-autoseo)

> Hệ thống điều phối Marketing nội bộ: Phân tích website, nghiên cứu từ khóa, lập kế hoạch nội dung, AI viết bài chuẩn SEO tiếng Việt có kiểm duyệt và tự động xuất bản theo lịch lên WordPress & Magento 2.

[![CI Quality & Security Gate](https://github.com/sontung098utc/addp-autoseo/actions/workflows/ci.yml/badge.svg)](https://github.com/sontung098utc/addp-autoseo/actions)

---

## 1. Công nghệ chính
* **Core Framework:** Laravel 11 + Filament 3 (PHP 8.2)
* **Cơ sở dữ liệu & Caching:** MariaDB 10.6 + Redis 7.2
* **Hàng đợi & Lập lịch:** Laravel Queue Worker (Redis driver) + Task Scheduler
* **Môi trường máy chủ:** DirectAdmin + OpenLiteSpeed (LSPHP 8.2) / Docker & Docker Compose
* **CMS kết nối:**
  * **WordPress:** REST API v2 + Application Passwords (hỗ trợ Rank Math / Yoast SEO)
  * **Magento 2:** Module bridge `Company_BlogApi` tương thích Mageplaza Blog (OAuth 1.0a / ACL theo Store View)

---

## 2. Lộ trình phát triển (Milestones)

* **V1.0 (19/11/2026):** Khung ứng dụng, AI viết bài tiếng Việt từ nguồn tài liệu công ty, Filament review UI, xuất bản bản nháp lên WordPress.
* **V1.1 (28/11/2026):** Tự động đăng bài hằng ngày lên WordPress & Magento 2 theo lịch (giờ VN), chống đăng trùng (`publication_key`), đối soát timeout và nút dừng khẩn cấp.
* **V1.2 (09/12/2026):** Crawler thu thập website, audit SEO on-page tiếng Việt, kế hoạch bài viết theo topic cluster, chạy thử nghiệm pilot 4 ngày (10 bài/CMS) và bàn giao cho Marketing.
* **V2.0 (02/01/2027):** Kết nối Google Search Console, cập nhật bài cũ tụt hạng (version/diff/rollback), gợi ý liên kết nội bộ tự động, phân tích đối thủ, hỗ trợ tiếng Anh và phân quyền đa thương hiệu.

---

## 3. Khởi chạy nhanh với Docker (Quickstart)

```bash
# 1. Sao chép file cấu hình môi trường
cp .env.example .env

# 2. Khởi động hệ thống containers (App, Nginx, DB, Redis, Worker, Scheduler)
docker-compose up -d --build

# 3. Cài đặt dependencies và khởi tạo ứng dụng
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate --seed

# 4. Truy cập giao diện quản trị Filament
# URL: http://localhost:8000/admin
```

---

## 4. Tài liệu kỹ thuật & Quy chuẩn

* [AGENTS.md](AGENTS.md): Quy tắc làm việc, bảo mật và phân quyền cho Kỹ sư và AI Agent.
* [TASKS.md](TASKS.md): Danh mục 30 mã công việc chi tiết và trạng thái WBS.
* [GITHUB_WORKFLOW.md](GITHUB_WORKFLOW.md): Quy ước phân nhánh Git, mở PR và kiểm duyệt chéo.
* [DAILY_REPORT_TEMPLATE.md](reports/DAILY_REPORT_TEMPLATE.md): Biểu mẫu báo cáo phiên và giờ công.
* [Contracts](contracts/): Đặc tả JSON Schema cho dữ liệu hệ thống (`WebsiteConnection`, `ContentBrief`, `ArticleVersion`, `Publication`).
* [Kiến trúc Auto-SEO Agent Platform](docs/seo-agent/AUTO_SEO_AGENT_ARCHITECTURE.md): Baseline hiện có, phạm vi theo kế hoạch gốc và các cải thiện đề xuất để phát triển thành hệ thống AI SEO/Marketing có điều phối Agent.
* [Kế hoạch trải nghiệm người dùng](docs/seo-agent/USER_EXPERIENCE_ROADMAP.md): Lộ trình giao diện theo hành trình Marketing, trạng thái tính năng và kiến trúc workflow n8n.
* [Phương án áp dụng M09 (Giai đoạn 0–4)](docs/seo-agent/APPLY_PLAN_V1.md): Rà soát hiện trạng, tồn đọng, cổng chất lượng và danh mục task áp dụng định hướng tự động hóa AI.
* [Phân tích thiết kế](docs/seo-agent/AI_AUTOMATION_DESIGN_ANALYSIS.md) và [Kế hoạch chia việc](docs/seo-agent/AI_AUTOMATION_EXECUTION_PLAN.md): Học từ claude-seo, đội AI SEO tự động, thang tin cậy.
* [Kịch bản thử nghiệm khả dụng](docs/seo-agent/USABILITY_TEST_SCRIPT.md): Thử mẫu giao diện với người dùng thật trước khi xây.
