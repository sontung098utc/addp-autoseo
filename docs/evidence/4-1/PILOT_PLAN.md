# KẾ HOẠCH CHẠY THỬ THỰC TẾ PILOT (TASK 4.1)
*Mốc: V1.2 - Tiêu chuẩn AC13 & DOC13*

## 1. MỤC TIÊU VÀ QUY MÔ
- **Thời gian:** 4 ngày làm việc quan sát (2 giờ/người/ngày).
- **Quy mô:** Xuất bản tối thiểu 10 bài/CMS (10 bài WordPress + 10 bài Magento 2 = 20 bài).
- **Chủ nhiệm:** Dev1 (Kỹ thuật & Hạ tầng) | **Reviewer:** Dev2 (UI & Nội dung).
- **Quy chuẩn:** Tuân thủ 100% nguyên tắc bảo mật, không commit credential, tuân thủ Idempotency chống đăng trùng.

## 2. KỊCH BẢN KIỂM THỬ 4 NGÀY
- **Ngày 1:** Khởi tạo danh mục 20 bài viết, phân loại theo Topic Cluster, kiểm tra 9 tiêu chuẩn chất lượng SEO bằng `ContentQualityChecker`.
- **Ngày 2:** Quy trình duyệt bài 3 vai trò (Writer -> Approver), thử nghiệm ca từ chối sửa đổi, xếp lịch xuất bản theo các khung giờ vàng tại Việt Nam.
- **Ngày 3:** Worker tự động quét lịch và đăng 20 bài lên WordPress REST API và Magento 2 `Company_BlogApi`, nhúng mã định danh Idempotency.
- **Ngày 4:** Mô phỏng ca lỗi mạng/timeout 504 -> đối soát tự động `needs_reconcile`, thử nghiệm cơ chế Dừng khẩn cấp `EmergencyStop`, tổng kết chi phí token AI.
