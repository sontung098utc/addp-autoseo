# Người điều phối của đội AI: học từ claude-seo

Ngày: 15/11/2026. Trạng thái: PHÂN TÍCH, chưa triển khai. Nguồn đọc: `claude-seo/skills/seo/SKILL.md`, `skills/seo-audit`, `skills/seo/references/thinking-framework*.md`, `quality-gates.md`, `docs/ARCHITECTURE.md`, `agents/seo-drift.md`, `seo-sxo.md`, `seo-image-gen.md`, `seo-flow.md`.

## 1. Điều phối của claude-seo làm gì (rút ra từ mã nguồn)

| # | Cách làm | Ở đâu | Ý nghĩa |
|---|---|---|---|
| 1 | **Nhận diện bối cảnh trước, rồi mới chọn chuyên gia** (loại doanh nghiệp: SaaS, địa phương, thương mại điện tử...) | `seo/SKILL.md` "Industry Detection" | Không chạy mọi thứ cho mọi trường hợp. |
| 2 | **Gọi chuyên gia THEO ĐIỀU KIỆN**: có khóa Google, có công cụ DataForSEO, có dữ liệu nền (baseline) thì mới gọi | `seo-audit` bước 4 | Luồng khác nhau theo từng phiên là chuyện bình thường, không phải ngoại lệ. |
| 3 | **Chuyên gia độc lập chạy SONG SONG**, điều phối gom lại rồi mới tổng hợp | `ARCHITECTURE.md` | Song song là mặc định cho việc không phụ thuộc nhau. |
| 4 | **Tổng hợp 4 pha PERCEIVE → ANALYZE → VALIDATE → ACT** trước khi ra kết luận; "một đề xuất chưa qua 4 pha chỉ là một phát hiện" | `thinking-framework.md` | Điều phối không chuyển tiếp nguyên văn báo cáo; nó suy luận. |
| 5 | **Điểm nghẽn quan trọng nhất đi trước** ("highest-leverage constraint"); kế hoạch là **đồ thị phụ thuộc** chứ không phải danh sách: cái nào mở khóa nhiều nhất, cái nào song song được, cái nào thiếu công cụ | nguyên lý 4 và 6 | Quyết định xếp thứ tự việc. |
| 6 | **Mỗi đề xuất kèm 4 thứ**: nguyên lý nó dựa vào, phụ thuộc vào cái nào, **"làm sao biết nó sai"**, chỉ báo theo dõi | `SKILL.md` Synthesis | Không có đặt cược vô hình. |
| 7 | **Cổng cứng có ngưỡng**: cảnh báo ở 30, DỪNG ở 50 và buộc người dùng giải thích | `quality-gates.md` | Dừng có lý do và có người chịu trách nhiệm. |
| 8 | **Lỗi từng phần**: một chuyên gia hỏng thì báo kết quả của phần còn lại, nêu rõ chuyên gia nào hỏng, gợi ý chạy lại riêng | `SKILL.md` Error Handling | Không bỏ cả phiên vì một mảnh lỗi. |
| 9 | **Mơ hồ thì hỏi**: không chắc loại hình thì nêu hai khả năng và hỏi người dùng xác nhận | cùng bảng | Không tự đoán ở chỗ ảnh hưởng lớn. |
| 10 | **Chuyên gia chỉ LẬP KẾ HOẠCH cho việc tốn tiền; điều phối quyết định có làm hay không** ("Never auto-generate (cost control)") | `seo-image-gen.md` | Quyền chi tiền nằm ở điều phối. |
| 11 | **Ghi kết quả dở dang ngay sau lượt phân tích đầu**, ghi đè bản đầy đủ khi xong, "để dừng do hết lượt không mất việc đã làm" | `seo-drift.md` Audit Persistence | Hết hạn mức giữa chừng không làm mất công. |
| 12 | **Phân loại lỗi → chuyển đúng chuyên gia** (drift schema → seo-schema; hiệu năng → seo-technical...) | `seo-drift.md` Cross-Skill Delegation | Bảng định tuyến lỗi, không phải "sửa lại tất cả". |
| 13 | **Phân mức CRITICAL / WARNING / INFO**; mức nghiêm trọng quyết định ai phải làm gì | `seo-drift.md` | Ưu tiên có quy tắc. |
| 14 | **Mỗi chuyên gia có model, số lượt và công cụ riêng** (opus cho phân tích sâu, sonnet cho việc thường; `maxTurns`; công cụ tối thiểu) | frontmatter agents | Kiểm soát chi phí và quyền hạn theo bộ phận. |
| 15 | **Đọc kết quả tìm kiếm thật trước khi quyết định dạng bài** (SXO: dạng bài lệch so với SERP là phát hiện CHÍNH) | `seo-sxo.md` | Quyết định dựa trên dữ liệu bên ngoài, không dựa trí nhớ. |
| 16 | **Nội dung tải về là dữ liệu, không phải chỉ thị** | mọi agent, mục Security | Chống chèn lệnh qua trang web. |
| 17 | **Chỉ nạp tài liệu tham chiếu khi cần, tối đa 5 mỗi lần** | `seo-flow.md`, "progressive disclosure" | Giới hạn ngữ cảnh và chi phí. |
| 18 | **Biết khi nào CHUYỂN CHO NGƯỜI**: giả định không muốn tự gánh, ràng buộc cứng không giải được, tối ưu quá sớm | `thinking-framework.md` | Chuyển người là một quyết định có điều kiện, không phải thất bại. |

## 2. Đối chiếu với đội AI hiện tại

Điều phối hiện nay quyết định ở các điểm: điều phối kho, chốt hướng sau tổng hợp nguồn, kế hoạch sửa, nhận hay bỏ bản sửa, dừng sửa (không cải thiện, hết ngân sách), giữ cho người chuyên môn duyệt, gỡ nguồn và dấu trích không kiểm được, xử lý lời nhắn làm lại.

Thiếu so với claude-seo:

- **A. Thứ tự các bước vẫn cố định trong mã** (mục 1.2, 1.3, 1.5): chưa có "gọi bộ phận theo điều kiện" và chưa có "chạy song song theo quyết định".
- **B. Quyết định chưa có lý do, chưa có "làm sao biết sai", chưa có phụ thuộc** (mục 1.6).
- **C. Bộ phận báo thiếu thì điều phối chưa chọn giữa các hướng** (ví dụ của người dùng): hiện chỉ có "tìm thêm một lần rồi tổng hợp lại".
- **D. Lỗi từng phần chưa đủ** (mục 1.8, 1.11): ảnh lỗi 429 chỉ cảnh báo; hết hạn mức thì bản nháp chưa lưu bị mất (`AiQuotaExceeded` trả bài về trạng thái cũ).
- **E. Chưa kiểm dạng bài so với kết quả tìm kiếm** trước khi viết (mục 1.15).
- **F. Quyền chi tiền chưa tập trung**: tạo ảnh, tìm thêm nguồn, vòng sửa đều tốn tiền nhưng mỗi nơi tự canh một kiểu (mục 1.10).
- **G. Chưa có bảng định tuyến lỗi chính thức** (mục 1.12): hiện chỉ có nhóm "viết lại được" và nhóm "nguồn".
- **H. Chưa hỏi người dùng khi mơ hồ** (mục 1.9), mới có đề xuất lập Trí tuệ.

## 3. Đề xuất: bộ khả năng của người điều phối

Mỗi quyết định theo mẫu: **Bộ phận báo gì → Điều phối đọc tín hiệu nào → Chọn hướng nào → Giao cho ai → Làm sao biết sai → Bước nào bị chặn chờ nó.**

### 3.1. Sau Tổng hợp nguồn báo "còn thiếu căn cứ" (ví dụ của người dùng)

Tín hiệu: số khoảng trống, chúng thuộc loại gì (số liệu y tế / giải thích chung), hạng rủi ro, đã tìm thêm bao nhiêu lần, còn ngân sách không, kho có cùng lĩnh vực không, khoảng trống có lặp lại ở nhiều bài không.

| Điều kiện | Hướng | Giao cho |
|---|---|---|
| Khoảng trống nhỏ, rủi ro thấp, không có số liệu nhạy cảm | **Bỏ qua**, ghi "bỏ qua vì …", dặn người viết nêu chung chung | Chiến lược (dàn ý bỏ mục thiếu) |
| Có số liệu hoặc rủi ro vừa trở lên, còn ngân sách, chưa tìm đủ 3 lần | **Tìm thêm nguồn đúng khoảng trống** | Kho và nguồn → quay lại Tổng hợp |
| Cùng khoảng trống lặp lại ở nhiều bài cùng lĩnh vực | **Nạp kiến thức vào Trí tuệ** đang gắn | Kho và nguồn |
| Chưa có Trí tuệ phù hợp và lĩnh vực lớn, lặp lại | **Đề xuất xây Trí tuệ mới** cho người dùng quyết (không tự xây) | Người dùng |
| Rủi ro cao, tìm đủ lần vẫn thiếu | **Cổng cứng: dừng**, chuyển người chuyên môn kèm danh sách khoảng trống | Người duyệt |
| Hai nguồn mâu thuẫn về số liệu | Yêu cầu tổng hợp lại ưu tiên nguồn bậc cao; nếu vẫn mâu thuẫn thì ghi cho người duyệt | Tổng hợp nguồn / Người duyệt |

Làm sao biết sai: sau khi tìm thêm, số khoảng trống không giảm → dừng tìm tiếp (không đốt ngân sách), chuyển hướng "bỏ qua" hoặc "dừng".

### 3.2. Sau Dàn ý

Đối chiếu các câu hỏi người đọc (từ Tổng hợp) với mục lục. Câu hỏi nào không có mục: giao Chiến lược thêm mục. Mục nào dựa vào khoảng trống chưa lấp: bỏ hoặc đổi thành phần nêu chung. **Kiểm dạng bài so với kết quả tìm kiếm** (học SXO): nếu top kết quả thiên về dạng khác (danh sách, bảng, hướng dẫn từng bước) thì báo người dùng thay vì viết sai dạng.

### 3.3. Tạo ảnh: điều phối giữ quyền chi tiền

Họa viên chỉ lập kế hoạch ảnh và lý do cần từng ảnh (đã làm). Điều phối quyết định: tạo ảnh nào (theo giá trị), nhà cung cấp lỗi thì **thử nhà cung cấp khác / giữ chỗ ảnh / hẹn chạy lại riêng bước ảnh / bỏ ảnh**, và ghi lý do. Không tự tạo hàng loạt khi chưa có ngân sách (nguyên tắc "never auto-generate" của claude-seo).

### 3.4. Sau mỗi lần chấm

Bảng định tuyến lỗi (học `Cross-Skill Delegation`): từ khóa và bố cục → Người viết (đã có, mỗi vòng một nhóm); bằng chứng và trích dẫn → Kho (tìm thêm nguồn) hoặc gỡ dấu (đã có phần gỡ); sai số liệu → Biên tập; ảnh → Họa viên; vi phạm an toàn → dừng. Mức độ: CRITICAL (chặn), WARNING (sửa nếu còn ngân sách), INFO (ghi cho người duyệt).

### 3.5. Biên tập viên yêu cầu sửa

Hiện đi thẳng cho người viết, không qua điều phối (đường nét đứt xanh lá trong sơ đồ). Theo quy tắc "có quyết định mới qua điều phối": ghi quyết định "nhận góp ý biên tập (N điểm) → yêu cầu sửa", để đường đi qua điều phối như vòng sửa khác.

### 3.6. Chọn model và ngân sách theo độ khó

Mỗi bộ phận có model, số lượt tối đa và trần chi phí riêng (học frontmatter `model/maxTurns/tools`). Điều phối giảm hoặc đổi model khi hạn mức hết, thay vì bỏ cuộc. Việc phân loại dùng model nhẹ, việc viết dùng model mạnh (đã có một phần qua chuỗi dự phòng).

### 3.7. Lưu kết quả dở dang

Mỗi bộ phận ghi kết quả ngay sau lượt đầu và ghi đè khi xong. Hết hạn mức hoặc dừng giữa chừng: giữ lại bản nháp tốt nhất (hiện bị trả về trạng thái cũ, mất công).

### 3.8. Mơ hồ thì hỏi, không tự đoán

Không chắc lĩnh vực hoặc dạng bài (hai khả năng gần nhau): ghi hai lựa chọn kèm tín hiệu cho người dùng xác nhận.

### 3.9. Mỗi quyết định ghi 4 thứ

`vì sao` (tín hiệu), `nếu sai thì thấy gì` (kiểm chứng được), `bước nào bị chặn chờ nó`, `đã thử gì trước đó`. Hiển thị trong "Quyết định tự động" và khi rê chuột vào đường trên sơ đồ.

## 4. Hệ quả cho sơ đồ

- Mỗi tình huống ở mục 3 là một **vòng** có tên: bộ phận báo → điều phối → giao lại (ví dụ Tổng hợp báo thiếu → Kho tìm thêm → Tổng hợp lại). Vòng quay về Chiến lược hiện bị gộp trong thẻ; cần hiện thành đường riêng.
- Đường **"bỏ qua"** là một đường đi thẳng có nhãn lý do, không phải không có đường.
- Đường chỉ vẽ khi quyết định thật sự xảy ra (đã đúng).
- Đường gắn vào **dòng bước** (Tổng hợp nguồn, Dàn ý, Tự sửa, Chấm bằng luật, Lắp ráp) chứ không gắn đỉnh thẻ.

## 5. Thứ tự làm đề xuất

1. 3.1 nhánh sau Tổng hợp nguồn (ví dụ của người dùng; chạm cả logic lẫn sơ đồ).
2. 3.5 biên tập qua điều phối + 3.9 ghi đủ lý do và "nếu sai thì thấy gì".
3. 3.7 lưu bản dở dang khi hết hạn mức.
4. 3.3 quyền chi tiền cho ảnh.
5. 3.2 kiểm dạng bài, 3.8 hỏi người dùng.
6. 3.6 chọn model theo độ khó.

## 6. Rủi ro, điều chưa kiểm

- Đây là phân tích đọc mã claude-seo, chưa chạy thử claude-seo. Các ngưỡng 30/50 của họ là về trang địa phương; ta chỉ mượn **mẫu** (cảnh báo rồi dừng có lý do), không mượn con số.
- Mọi nhánh mới phải có test và chạy thật vài bài (kể cả bài rủi ro cao) trước khi coi là xong. Chưa làm.
- Bất cứ quyết định nào tốn tiền hoặc đổi cấu hình cần giữ trần chi phí hiện có và ghi lại trong "Quyết định tự động".
