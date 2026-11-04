# QUY TRÌNH PHÂN NHÁNH VÀ QUẢN LÝ GITHUB (GITHUB_WORKFLOW.md)
*Dự án: Phần mềm Auto SEO & Tự động đăng bài (addp-autoseo)*

Tài liệu này quy định chiến lược phân nhánh (branching strategy), quản lý Pull Request (PR), kiểm tra CI và quy trình phát hành nhằm ngăn chặn xung đột code và đảm bảo tính ổn định của hệ thống.

---

## 1. QUY ƯỚC PHÂN NHÁNH (BRANCHING CONVENTIONS)

1. **Nhánh vĩnh viễn (Permanent Branches):**
   * `main`: Nhánh chạy production/stable. Bắt buộc bảo vệ (branch protection). Nghiêm cấm force push hoặc commit trực tiếp. Chỉ nhận code thông qua Pull Request từ `release/*` hoặc `hotfix/*`.
   * `develop`: Nhánh tích hợp chính (Integration branch). Môi trường staging triển khai từ nhánh này. Không nhận commit trực tiếp.

2. **Nhánh công việc tạm thời (Feature & Task Branches):**
   * Định dạng bắt buộc: `<loại>/<nhóm>/<mã-việc>/<người-phụ-trách>/<mô-tả-ngắn>`
   * Trong đó:
     * `<loại>`: `feat` (tính năng), `fix` (sửa lỗi), `chore` (hạ tầng, cấu hình), `docs` (tài liệu), `test` (kiểm thử).
     * `<nhóm>`: Mã Epic (ví dụ `m01`, `m02`, ...).
     * `<mã-việc>`: Mã công việc trong bảng WBS (dấu chấm chuyển thành gạch ngang, ví dụ `0.2` thành `0-2`).
     * `<người-phụ-trách>`: `dev1` hoặc `dev2`.
     * `<mô-tả-ngắn>`: Viết thường, nối bằng dấu gạch ngang (ví dụ `infra-ci`, `wordpress`, `ai-poc`).
   * **Ví dụ mẫu:**
     * `chore/m01/0-2/dev1/infra-ci`
     * `feat/m01/0-3/dev2/ai-poc`
     * `feat/m02/1-2/dev2/wordpress`

---

## 2. QUY TRÌNH MỞ VÀ DUYỆT PULL REQUEST (PR)

1. **Tạo nhánh:** Cắt nhánh mới nhất từ `develop` (`git checkout -b <tên-nhánh> develop`).
2. **Commit:** Commit có ý nghĩa, tuân thủ Conventional Commits (ví dụ `feat(wordpress): implement post draft creation adapter`).
3. **Mở Pull Request vào `develop`:**
   * Tiêu đề PR: `[Mã việc] Tên tóm tắt công việc` (ví dụ: `[0.2] Setup Docker environment and CI workflow`).
   * Mô tả PR bắt buộc gồm:
     * **Mục tiêu task** (khớp với TASKS.md / Sheet).
     * **Danh sách file thay đổi** (nằm trong allowlist được phân công).
     * **Bằng chứng kiểm thử:** Kết quả chạy test cục bộ, log xuất bản thử nghiệm, hình ảnh minh họa (nếu có).
     * **Những điểm chưa kiểm thử / Cảnh báo:** Nếu chưa có tài khoản CMS thật thì ghi rõ `Đã test Mock, chưa test Live CMS`.
4. **Quy tắc Review chéo:**
   * Tác giả PR không được tự duyệt PR của chính mình.
   * Dev phụ trách còn lại (Dev1 hoặc Dev2) bắt buộc review code, kiểm tra tính bảo mật (không lộ credentials, không vi phạm SSRF/XSS) và kiểm tra log CI.
   * CI checks (linting, automated tests) phải hiển thị trạng thái xanh (Passed) thì PR mới đủ điều kiện merge.
5. **Merge và dọn dẹp:**
   * Sử dụng hình thức **Squash and Merge** để giữ lịch sử git gọn gàng trên `develop`.
   * Xóa nhánh tính năng sau khi merge thành công.

---

## 3. QUY TRÌNH PHÁT HÀNH (RELEASE)

* Khi hoàn thành đầy đủ các task của một mốc (V1.0, V1.1, V1.2, V2.0):
  1. Cắt nhánh phát hành: `release/v1.0.0` từ `develop`.
  2. Thực hiện kiểm thử E2E trên môi trường staging theo đúng tiêu chí nghiệm thu của mốc.
  3. Mở PR từ `release/v1.0.0` vào `main`.
  4. Sau khi merge vào `main`, tạo Git Tag tương ứng (ví dụ: `v1.0.0`).
  5. Đồng bộ ngược từ `main` về lại `develop`.
