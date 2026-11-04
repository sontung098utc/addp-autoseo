# BIỂU MẪU BÁO CÁO PHIÊN / NGÀY LÀM VIỆC (DAILY_REPORT_TEMPLATE.md)
*Dự án: Phần mềm Auto SEO & Tự động đăng bài (addp-autoseo)*

Cuối mỗi phiên làm việc hoặc cuối ngày, nhân sự thực hiện (hoặc AI Agent) bắt buộc lập báo cáo theo mẫu này và lưu tại thư mục `reports/daily/YYYY-MM-DD-<dev>.md`.

---

## 1. THÔNG TIN PHIÊN LÀM VIỆC
* **Ngày làm việc:** YYYY-MM-DD
* **Nhân sự thực hiện:** Dev1 / Dev2 / AI Assistant
* **Mã công việc (Task ID):** (Ví dụ: `0.2`, `0.3`)
* **Tên nhánh Git:** `...`
* **Base Commit SHA:** `...`
* **Commit SHA hoàn thành:** `...`
* **Pull Request URL (nếu có):** `...`

---

## 2. NỘI DUNG ĐÃ THỰC HIỆN
* [x] Mô tả chi tiết việc 1
* [x] Mô tả chi tiết việc 2
* [ ] Việc chưa hoàn tất (nếu có)

---

## 3. BẰNG CHỨNG KIỂM THỬ (EVIDENCE CONTRACT)
* **Môi trường thử nghiệm:** Local Docker / Mock / Staging
* **Lệnh kiểm thử đã chạy:**
  ```bash
  composer test
  # hoặc php artisan test --filter=...
  ```
* **Kết quả:**
  * Tests passed: ...
  * Tests failed: ...
  * Mã thoát (Exit code): `0`
* **Trạng thái kết nối CMS / Dịch vụ bên ngoài:**
  * WordPress: [ ] Đã test Live | [x] Test Mock | [ ] Chưa chạy
  * Magento 2: [ ] Đã test Live | [x] Test Mock | [ ] Chưa chạy
  * AI Provider: [x] Đã test Live | [ ] Test Mock | [ ] Chưa chạy

---

## 4. CHI PHÍ & THỜI GIAN
* **Giờ công thực tế phiên này:** ... giờ (Không tính thời gian máy/AI chạy ngầm)
* **Số bài/ảnh đã thử nghiệm:** ... bài, ... ảnh
* **Chi phí API phát sinh (ước tính):** ... VND / USD

---

## 5. KHÓ KHĂN & ĐIỂM NGHẼN (BLOCKERS)
* *Ghi rõ nếu thiếu quyền truy cập tài khoản WordPress, thiếu API key hoặc cần quyết định từ Quản lý.*

---

## 6. XÁC NHẬN REVIEWER
* **Reviewer:** Dev1 / Dev2
* **Đánh giá:** [ ] Đồng ý nghiệm thu | [ ] Yêu cầu sửa đổi
* **Ghi chú của Reviewer:** ...
