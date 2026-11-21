---
key: health-infographic
version: 1
role: designer
title: Ảnh có giá trị cho người đọc bài sức khỏe: một ảnh một thông điệp, chữ ngắn tiếng Việt khi cần, không rườm rà
status: draft
model_tier: reasoning
rubric_refs:
  - IMG01
lineage:
  source: addp-autoseo/original
  source_version: 1
  license: internal
  adaptations: Soạn mới theo phản hồi vận hành: ảnh trước đây chung chung, rườm rà, ít giá trị cho người đọc bài sức khỏe và không bao giờ có chữ dù người duyệt yêu cầu. Playbook này đặt tiêu chí giá trị cho ảnh và cách dùng chữ ngắn đúng chỗ. Chưa đo bằng người đọc thật.
reviewed_at: 2026-11-21
---
# Mục tiêu
Mỗi ảnh phải giúp người đọc **hiểu nhanh hơn hoặc làm đúng hơn**. Ảnh trang trí, cảnh chung chung kiểu "người cười cầm cốc nước" không có giá trị: bỏ. Bài sức khỏe cần ít ảnh nhưng đúng chỗ, sạch, dễ nhìn trên điện thoại.

# Bài kiểm "ảnh này làm việc gì?"
Chỉ giữ ảnh nếu trả lời được một trong các câu:
- Giải thích **cơ chế/khái niệm** khó diễn đạt bằng chữ (ví dụ đường đi của một quá trình, các nhóm thực phẩm trong một đĩa ăn).
- Cho thấy **cách làm** từng bước (tư thế, chế biến, thứ tự).
- **So sánh** hai lựa chọn song song (nên/hạn chế) mà không phán xét đạo đức.
- Giúp **nhớ nhanh** một quy tắc then chốt (đĩa ăn cân đối, nhóm thực phẩm, mốc cần đi khám).
Không trả lời được: không tạo ảnh cho mục đó.

# Dạng ảnh ưu tiên cho sức khỏe, dinh dưỡng
1. **Sơ đồ/infographic đơn giản:** 1 thông điệp, 3–5 phần tử, nhiều khoảng trống, bố cục rõ trên-xuống hoặc trái-phải.
2. **Đĩa ăn/khẩu phần minh họa** bằng hình vẽ phẳng các nhóm thực phẩm quen thuộc ở Việt Nam, không cần chính xác từng gram nếu không có nguồn.
3. **Biểu tượng giải thích** (đường đi, cơ quan ở mức sơ đồ hóa, không giải phẫu chi tiết).
4. **Ảnh bìa:** một ẩn dụ hình ảnh gọn gàng gắn với chủ đề, màu dịu, không gây sợ.
Tránh: ảnh stock chung chung, nhiều chi tiết nhỏ, nhiều màu chói, hình chụp bệnh/vết thương/y cụ đáng sợ, mặt người nhận ra được, ảnh trước-sau, ảnh thể hiện kết quả điều trị, logo/nhãn hiệu.

# Chữ trên ảnh
- **Mặc định không có chữ.** Chỉ có chữ khi người duyệt yêu cầu hoặc khi ảnh là sơ đồ/infographic mà chữ là phần giá trị.
- Khi có chữ: **1–3 cụm, mỗi cụm ≤ 40 ký tự**, tiếng Việt có dấu đầy đủ, lấy từ tiêu đề mục/thuật ngữ của chính bài hoặc từ cụm chữ người duyệt cung cấp. **Không bịa số liệu, liều lượng, tỷ lệ, kết quả, tên thương hiệu.** Không đặt đoạn văn dài hay chữ nhỏ.
- Chữ lớn, nét đậm, tương phản cao với nền, đặt ở vùng thoáng, không chồng lên chi tiết quan trọng. Model ảnh vẽ chữ tiếng Việt dễ sai dấu: giữ ít chữ để người duyệt dễ kiểm.
- Chữ chỉ nằm ở trường `overlay_text`; mô tả ảnh (`prompt`) tả bố cục và để trống chỗ cho chữ, không nhét câu chữ vào mô tả.

# Thống nhất cả bài
Một bảng màu (2–3 màu chính + trung tính), một phong cách nét (phẳng, bo tròn), cùng độ sáng. Bìa và ảnh trong bài trông cùng một bộ. Màu theo thương hiệu nếu hồ sơ doanh nghiệp có.

# Số lượng và vị trí
Bìa 1 ảnh (trừ khi thư viện có ảnh phù hợp), ảnh trong bài tối đa 1–2 và chỉ ở mục có việc cho ảnh. Ít hơn, đúng chỗ, tốt hơn nhiều ảnh yếu. Đặt ảnh ngay sau đoạn mở đầu mục mà nó giải thích.

# Alt
Tiếng Việt, tả đúng điều ảnh thể hiện và ý nghĩa với mục; nếu ảnh có chữ, alt nhắc lại nội dung chữ. Không nhồi từ khóa.

# Khi nhận lời dặn của người duyệt
Làm đúng lời dặn trong phạm vi an toàn: đổi chủ đề/bố cục/màu/có chữ theo ý họ. Nếu lời dặn đòi điều bị cấm (người thật, logo, trước-sau, hình đáng sợ), giữ quy tắc và nói rõ trong ghi chú rằng đã điều chỉnh vì sao.

# Điều kiện đạt
Mỗi ảnh có việc rõ ràng; thông điệp đọc được trong 3 giây; chữ (nếu có) đúng chính tả và đúng nguồn; không vi phạm rào chắn an toàn.
