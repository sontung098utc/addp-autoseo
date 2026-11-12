# Kho trí tuệ chuyên ngành (y tế, dược): phân tích và lộ trình

*Soạn 12/11/2026, từ lần dựng thử Kho Đái tháo đường (Brain #2) trên preview. Bản nháp do AI soạn, **chờ chuyên gia nội tiết, dược sĩ và pháp chế thẩm định**. Mức bằng chứng: chạy thật với Gemini và CLI `agy` trên dữ liệu thử `TRIAL-*`; chưa có người nghiệm thu.*

## 1. Chẩn đoán: vì sao kho chưa giúp AI viết như chuyên gia

| Tầng | Bằng chứng | Hệ quả |
|---|---|---|
| A. Hồ sơ Trí tuệ rỗng | Tông giọng, mức trang trọng, xưng hô trống; thuật ngữ không có định nghĩa; không có bài mẫu; mẫu cấm nêu ngưỡng, thuốc, liều | AI không có giọng và logic chuyên gia; mẫu còn ngăn nội dung kiểu dược sĩ |
| B. Nguồn sai tầng | 6 nguồn nhập đều là trang giáo dục bệnh nhân; Sổ nguồn 8 mục, 0 mục chuyên gia xác nhận; chỉ biết "tên miền tin cậy", không phân bậc bằng chứng | Kho đúng nhưng nông; mọi khẳng định bị cảnh báo thiếu nguồn |
| C. Mô hình tri thức phẳng | Mục là 1–3 câu; không có đối tượng áp dụng, mức khuyến cáo, mức bằng chứng, năm ban hành, chống chỉ định; 0 giá trị có cấu trúc | Mất điều kiện đi kèm; không biết hướng dẫn nào mới hơn hay nguồn nào mâu thuẫn |
| D. Trích xuất một lượt | Luật chống bịa chỉ kiểm câu trích có trong nguồn, không kiểm nghĩa (từng gặp diễn đạt sai quan hệ "trong đó 10%") | Sai nghĩa lọt qua |
| E. Từ kho đến bài | Người viết nhận ~5 mục theo từ khóa; con số trong bài lấy từ trí nhớ model; EVID01 kiểm ở mức cả bài | Bài nghe chuyên gia nhưng không truy vết được từng câu |
| F. Không có người ký duyệt, không có vòng học | Không có vai trò chuyên gia kèm checklist; không có bộ câu hỏi đo; chỗ thiếu tri thức không điều khiển việc tìm nguồn | Lần sau không khá hơn lần trước |

## 2. Thiết kế hướng tới

1. **Hồ sơ chuyên ngành (Domain Spec)** dựng theo quy trình cố định: phạm vi hành nghề và rủi ro; độc giả và giọng; thuật ngữ có định nghĩa và từ cần tránh (ICD-10, MeSH, tài liệu của Bộ Y tế); quy tắc lập luận theo loại bài; điều kiện mở khóa ngưỡng và thuốc (chỉ khi có nguồn bậc 1 và chuyên gia ký).
2. **Thang bậc nguồn** theo loại bằng chứng: hướng dẫn lâm sàng quốc gia và quốc tế; tổng quan hệ thống; giáo dục sức khỏe uy tín; riêng thuốc là Dược thư quốc gia và tờ hướng dẫn sử dụng đã đăng ký. Bậc quyết định mục nào được tự nhận.
3. **Mục tri thức lâm sàng**: đối tượng, điều kiện, mức khuyến cáo và bằng chứng, tổ chức và năm ban hành, hiệu lực, chống chỉ định; quan hệ "thay thế" và "mâu thuẫn".
4. **Dựng kho nhiều lượt**: trích xuất, kiểm lại nghĩa bằng lượt AI độc lập, đối chiếu chéo nguồn, chuyên gia duyệt.
5. **Gói chứng cứ theo loại bài** và **trích dẫn từng khẳng định** có cổng kiểm cứng.
6. **Vòng học và đo lường**: chỗ thiếu của bài thành hàng đợi tìm nguồn; sửa của chuyên gia thành quy tắc; bộ câu hỏi vàng; theo dõi độ mới; bảng số đo kho.
7. **Quản trị**: vai trò chuyên gia ký duyệt kèm checklist, nhật ký ai duyệt gì, chính sách tự nhận theo bậc nguồn.

## 3. Trạng thái triển khai (đợt 1, ngày 12/11/2026)

| Hạng mục | Trạng thái |
|---|---|
| Hồ sơ đái tháo đường điền đủ giọng, xưng hô, thuật ngữ có định nghĩa, quy tắc lập luận, loại bài (bản nháp AI, ghi `settings.profile_review`, `updated_by` để trống) | Xong; **chưa chuyên gia xác nhận**; **chưa có bài mẫu** |
| Prompt người viết nhận đủ 15 ý "Nên/Tránh" và 20 quy tắc lập luận (trước cắt ở 12) | Xong |
| Luật chấm CITE01: mỗi đoạn có khẳng định phải có `[KB#id]`; `[KB#id]` giả, chưa duyệt hoặc sai Trí tuệ bị chặn (P0) | Xong, có test |
| Luật chấm MED01: có đoạn hướng tới bác sĩ và câu "thông tin tham khảo" (P1) | Xong, có test |
| Bộ câu hỏi vàng (25 câu, bản nháp AI) và lệnh `php artisan brain:eval {brain} [--include-proposed]` | Xong, có test; **chờ chuyên gia gán nhãn** |
| Thang bậc nguồn theo loại bằng chứng, mục tri thức lâm sàng, kiểm lại nghĩa, vai trò chuyên gia, vòng học tự động | **Chưa làm** (đợt 2 và 3) |

### Số đo hiện tại của Kho Đái tháo đường (preview, 113 mục, tất cả chờ duyệt)
- Độ phủ truy xuất, chỉ mục đã duyệt (dùng được ngay): **0/25** câu (chưa có mục nào được chuyên gia duyệt).
- Tiềm năng nếu duyệt hết: **19/25** phủ đủ, 5 một phần, 1 không phủ. Thiếu nội dung: ăn uống, sổ theo dõi, an toàn khi vận động, hỗ trợ từ gia đình, mục tiêu HbA1c theo Bộ Y tế.
- Còn một lỗi truy xuất: ngưỡng đường huyết lúc đói (126 mg/dL) có trong kho nhưng không lên top-8 cho câu hỏi liên quan.

## 6. Quy trình "Xây kho chuyên ngành" do hệ thống tự chạy (đợt 1b)

Các mục ở phần 3 (hồ sơ, bộ câu hỏi) lúc đầu do người điền thử bằng tay để đo. Điều đúng là **hệ thống tự làm khi người dùng chọn xây kho**; phần này mô tả quy trình đó (`DomainBrainBuilder`, lệnh `php artisan brain:build-domain {brain} [--review]`, nút "Xây kho chuyên ngành" và "Rà soát kho" ở Trí tuệ → Cài đặt). Mỗi bước là một bước của phiên chạy loại "Xây kho trí tuệ" (xem ở Đội AI → Phiên chạy), ghi kỹ năng đã dùng.

| Bước | Ai làm | Kỹ năng (playbook) | Cổng |
|---|---|---|---|
| phạm vi (`scope`) | hệ thống | - | Lĩnh vực rủi ro cao: mọi mục chờ chuyên gia, bắt buộc kiểm lại nghĩa |
| kế hoạch nguồn (`sources`) | strategist (AI) | `strategist/brain-source-plan` | Thứ tự: trang chủ lực của mẫu → tìm web thật (nếu cấu hình Tavily) → URL AI đề xuất; luật kiểm tên miền (chặn, trùng, URL sai); chỉ nạp được nguồn tải về thành công |
| nạp và rút mục (`ingest`) | editor (AI) | `editor/brain-claim-extract` (giữ điều kiện, số liệu nguyên vẹn, tiếng Việt) | Tầng C không bao giờ tự nhận |
| kiểm lại nghĩa (`verify`) | editor (AI độc lập) | `editor/brain-verify-claim` | Mục tự nhận mà không khớp bị hoàn về chờ duyệt; chỉ gắn nhãn, không sửa/duyệt |
| gán chủ đề (`topics`) | AI (chỉ chọn chủ đề có sẵn) | - | Chủ đề lạ bị bỏ |
| bộ câu hỏi và độ phủ (`eval`) | editor (AI soạn) + luật đo | `editor/brain-eval-questions` | Bộ câu hỏi là nháp AI chờ chuyên gia |
| bổ sung chỗ thiếu (`gaps`) | strategist (AI) | `strategist/brain-gap-fill` | Tối đa vài vòng, trong ngân sách; chỗ không lấp được chuyển cho chuyên gia |
| hồ sơ lĩnh vực (`profile`) | strategist (AI) | `strategist/brain-domain-spec` | Chỉ bổ sung trường trống; định nghĩa phải có mục kho hỗ trợ; nhãn `ai_draft`, không ghi tên người |
| báo cáo (`report`) | hệ thống | - | Số đo kho và danh sách việc cho chuyên gia (lưu ở `settings.build_report`) |

**Đã chạy thử bằng AI thật** (Gemini 3.5 Flash-Lite, ~3.000 ₫) trên một Trí tuệ mới dựng chỉ từ mẫu, không ai điền tay: 132 mục (123 khớp, 8 cần xem, 1 lệch nghĩa), 8/10 nguồn tải được, độ phủ tiềm năng 19/25 câu hỏi (76%), dùng được ngay 0% vì mọi mục chờ chuyên gia. Hệ thống tự phát hiện còn thiếu "Sổ theo dõi" và "Hỗ trợ từ gia đình" và chuyển cho chuyên gia.

**Phát hiện khi chạy thử:** nếu chỉ để AI đề xuất URL thì chỉ khoảng 3/18 URL tải được (đường dẫn trang quốc tế đổi liên tục, một số trang chặn máy), nên mỗi lĩnh vực cần **danh sách trang chủ lực** (`resources/brains/seeds/{domain_key}.php`, tách khỏi mẫu vì mẫu chỉ chứa cấu trúc) do người soạn chọn và kiểm tải, và cần **khóa tìm kiếm web thật** để tìm trang đúng. Cả hai đều là việc của người soạn mẫu và chuyên gia.

**Quyền và an toàn:** chỉ người duyệt trở lên chạy được; nội dung nguồn là dữ liệu trong khung nonce; AI chỉ trả JSON được kiểm schema; không bao giờ xác nhận nguồn trong Sổ nguồn, duyệt mục hay ghi đè trường hồ sơ do người điền thay chuyên gia.

## 4. Giới hạn
- AI không thay chuyên gia y khoa. Hồ sơ, thuật ngữ, bộ câu hỏi và từ điển Việt–Anh do AI soạn chỉ là đề xuất.
- Luật CITE01 và MED01 nhận khẳng định và cụm từ bằng quy tắc nên có thể bỏ sót hoặc báo nhầm; dấu `[KB#id]` hợp lệ không chứng minh câu đúng với mục được trích.
- Căn cứ pháp lý về quảng cáo thuốc và thực phẩm chức năng cần chuyên gia pháp chế xác nhận.

## 5. Lộ trình
- **Đợt 2**: mục tri thức lâm sàng; lượt AI kiểm lại nghĩa; nhập hướng dẫn bậc cao (Bộ Y tế, ADA, IDF); thang bậc nguồn theo loại bằng chứng; sửa xếp hạng truy xuất.
- **Đợt 3**: vòng học (chỗ thiếu thành hàng đợi nguồn, sửa của chuyên gia thành quy tắc); bảng số đo kho; quy trình dựng lĩnh vực mới từ Domain Spec; bộ câu hỏi vàng cho từng lĩnh vực.
