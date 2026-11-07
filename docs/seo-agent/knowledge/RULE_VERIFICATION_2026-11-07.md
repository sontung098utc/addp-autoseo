# Xác minh quy tắc SEO trích từ claude-seo (AG-K01) — 07/11/2026

> **Mục đích:** trước khi đổi rubric chất lượng (`AG-K03`), kiểm các quy tắc L1–L9 với **nguồn chính thức của Google**. Mỗi quy tắc có trạng thái: ✅ đã xác minh · 🟡 xác minh một phần · ⬜ chưa xác minh · ➖ quy tắc nội bộ (không cần nguồn Google).
> **Giới hạn:** xác minh bằng tìm kiếm trong tài liệu Google Search Central (4 truy vấn); **chưa đọc trực tiếp từng trang**. Các mốc ngày 2026 (ngừng FAQ rich result, cập nhật hướng dẫn AI) lấy từ tóm tắt kết quả tìm kiếm và changelog; **cần mở trang gốc để chốt** trước khi dùng làm căn cứ chặn bài. Chưa xác minh bằng dữ liệu thật của công ty.

## Bảng kết quả
| # | Quy tắc / hiện trạng trong repo | Kết quả đối chiếu | Trạng thái | Hàm ý cho rubric (AG-K03) |
|---|---|---|---|---|
| L1 | `MAX_KEYWORD_DENSITY_PERCENT = 3.0` (KW01) | Google mô tả *keyword stuffing* **định tính**: nhồi từ khóa/con số, lặp không tự nhiên, ngoài ngữ cảnh. Kết quả tìm kiếm **không** có trang nào nêu "tỉ lệ %" hay khẳng định "không có mật độ lý tưởng" → câu đó chưa quy cho Google được | 🟡 | Ngưỡng 3% **không có căn cứ Google**. Hạ thành **cảnh báo heuristic** (ghi rõ "kinh nghiệm, chưa hiệu chỉnh"), kèm kiểm "đọc có tự nhiên không"; không dùng làm điều kiện chặn cứng |
| L2 | `MIN_WORDS = 800`, chặn < 400 (LEN01) | Chưa tra trong vòng này | ⬜ | Giữ như heuristic, ghi "chưa xác minh"; cần tra phát biểu của Google về số từ và hiệu chỉnh trên dữ liệu thật |
| L3 | `FaqRule`, `faq_schema` trong đầu ra AI | 08/2023: FAQ rich result **chỉ còn cho trang chính phủ/sức khỏe nổi tiếng**. Changelog (theo tóm tắt tìm kiếm): thông báo 08/05/2026, **ngừng hiển thị từ 07/05/2026**, tài liệu bị gỡ 06/2026 | 🟡 (2023 đã xác minh; mốc 2026 cần mở trang gốc) | **Không ép bài phải có FAQ**; không kỳ vọng rich result từ FAQ schema. Giữ phần hỏi–đáp như **nội dung** nếu hữu ích; xem xét bỏ/hạ mức `FaqRule` |
| L4a | Meta description 120–160, title ≤ 60 (META01) | Google: **không có giới hạn cứng** độ dài; kết quả bị **cắt theo chiều rộng thiết bị**; nên ngắn gọn, mỗi trang tiêu đề khác nhau, không nhồi từ khóa, không dùng khuôn mẫu chung chung | ✅ | Các số 60/120/160 là **khuyến nghị hiển thị**, không phải luật → hạ xuống mức thông tin/P2, không chặn |
| L4b | (Chưa có) rule "metadata rập khuôn/trùng lặp" | Phù hợp khuyến nghị "tiêu đề/mô tả khác biệt từng trang, tránh boilerplate" (nguồn nêu rõ cho title; mô tả cần đọc trang gốc để chốt) | 🟡 | **Thêm rule** phát hiện trùng/rập khuôn giữa các bài |
| L5 | (Chưa có) rào chắn cho nội dung AI hàng loạt | **Scaled content abuse** là chính sách spam từ 03/2024: tạo **hàng loạt trang** (kể cả bằng AI) chủ yếu để thao túng thứ hạng/không thêm giá trị cho người dùng. **Không cấm AI**; nên kiểm chứng sự kiện và cân nhắc công bố cách tạo nội dung | ✅ | **Thêm guardrail** (K07): giới hạn bài/ngày/website, bắt buộc có nguồn/thông tin riêng, giữ nháp khi nghi ngờ; ghi nhận AI **không** được lợi thế riêng |
| L6 | Website Relevance Rule (chỉ gợi ý điều website làm được) | Quy tắc thiết kế của claude-seo | ➖ | Thêm vào playbook Chiến lược/Brief |
| L7 | Điểm audit = trung bình điểm URL (Dev1) vs điểm theo danh mục (claude-seo) | Hai thang nội bộ khác nhau, không phải quy tắc Google | ➖ | Luôn hiển thị `score_method`; không so sánh trực tiếp |
| L8 | Flesch Reading Ease (claude-seo) | Công thức cho **tiếng Anh**; không có hướng dẫn Google về điểm đọc hiểu | ⬜ | **Không dùng** cho tiếng Việt; cần thước đo khác, thử nghiệm riêng |
| L9 | Rubric là hằng số PHP, ghi chú "chưa hiệu chỉnh" | Nội bộ | ➖ | Chuyển thành dữ liệu có `nguồn/lý do/reviewed_at` |

## Điều cần làm tiếp (không chặn G0)
1. Mở trực tiếp các trang gốc dưới đây để chốt L1, L3, L4b (đặc biệt mốc ngày 2026) và ghi lại ngày xem.
2. Tra L2 (số từ) và dữ liệu thật của công ty để hiệu chỉnh `QualityThresholds`.
3. Sau khi chốt, tạo PR `AG-K03` (rubric có metadata) dựa trên bảng này.

## Nguồn (Google Search Central)
- [Spam Policies for Google Web Search](https://developers.google.com/search/docs/essentials/spam-policies) — keyword stuffing, scaled content abuse
- [Google Search's Guidance on Generative AI Content on Your Website](https://developers.google.com/search/docs/fundamentals/using-gen-ai-content)
- [What web creators should know about our March 2024 core update and new spam policies](https://developers.google.com/search/blog/2024/03/core-update-spam-policies)
- [Changes to HowTo and FAQ rich results (08/2023)](https://developers.google.com/search/blog/2023/08/howto-faq-changes)
- [Latest Google Search Documentation Updates (changelog)](https://developers.google.com/search/updates)
- [How to Write Meta Descriptions](https://developers.google.com/search/docs/appearance/snippet)
- [Influencing Title Links in Google Search](https://developers.google.com/search/docs/advanced/appearance/good-titles-snippets)
- [SEO Starter Guide](https://developers.google.com/search/docs/fundamentals/seo-starter-guide)
