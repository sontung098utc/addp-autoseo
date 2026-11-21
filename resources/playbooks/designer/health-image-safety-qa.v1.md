---
key: health-image-safety-qa
version: 1
role: designer
title: Kiểm ảnh bài sức khỏe trước khi duyệt: an toàn thông tin, chữ và dấu, không ngụ ý điều trị, nhắc người duyệt kiểm
status: draft
model_tier: cheap
rubric_refs:
  - IMG01
  - IMG02
  - SAFE01
lineage:
  source: addp-autoseo/original
  source_version: 1
  license: internal
  adaptations: Soạn mới cho vai trò Người kiểm ảnh, bổ sung cho designer/image-qa (học từ claude-seo/seo-images). Phần luật (ảnh trống, trùng, phân giải, tỉ lệ, alt) đã chạy bằng mã; playbook này liệt kê điều CẦN NGƯỜI nhìn: chữ sai dấu, hình ngụ ý hiệu quả điều trị, mô tả sai y khoa, hình gây sợ. Chưa có AI nhìn ảnh nên không khẳng định đã kiểm.
reviewed_at: 2026-11-21
---
# Mục tiêu
Danh sách nhắc cho người duyệt khi xem ảnh bài sức khỏe; hoặc tiêu chí cho AI nhìn ảnh khi sau này có.

# Các mục kiểm
1. **Chữ trên ảnh:** đúng dấu tiếng Việt, đúng chính tả, khớp bản chữ trong bài, không số liệu bịa.
2. **Ngụ ý điều trị:** ảnh không cho thấy kết quả điều trị, trước-sau, người "khỏi bệnh", hay hộp thuốc/sản phẩm nhận diện được.
3. **Đúng khái niệm:** sơ đồ không tả sai cơ chế hoặc nhóm thực phẩm; vị trí cơ quan ở mức sơ đồ hóa hợp lý. Nghi ngờ thì gắn cờ cho người có chuyên môn.
4. **Hình gây sợ hoặc xấu hổ:** vết thương, dụng cụ y tế đáng sợ, hình mỉa mai thể trạng.
5. **Tương phản và dễ đọc** trên điện thoại.
6. **Trùng ý và trùng bộ:** các ảnh trong bài cùng phong cách, không lặp một ý.
7. **Quyền:** không logo, mặt người nhận ra được, nhãn hiệu.

# Rào chắn
- Chỉ nhắc và đề xuất; không tự duyệt ảnh có chữ (người duyệt kiểm chữ và dấu).
- Không nói "đã kiểm" cho phần chưa có công cụ.

# Đầu ra
```json
{"checks":[{"item":"","status":"cần người xem|nghi ngờ|ổn theo luật","note":""}]}
```
