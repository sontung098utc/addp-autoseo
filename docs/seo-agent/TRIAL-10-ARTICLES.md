# Chạy thử 10 bài SEO thật bằng Gemini (12/11/2026)

*Mục đích: đo chất lượng, chi phí, thời gian của luồng thật (brief → viết → chấm → sửa) với AI thật, thay cho báo cáo POC cũ nghi mô phỏng (`reports/0-3_AI_POC_REPORT.md`).*

## 0. Mức kiểm chứng (theo AGENTS.md §1.2)

| Mức | Trạng thái |
|---|---|
| Đã viết code | Có (luồng đã có sẵn; chỉ sửa bảng giá + cách đếm token, xem §8) |
| Test giả lập đạt | Có: `tests/Feature/Agent` + `tests/Unit` = 686 đạt, 7 lỗi nền sẵn có, không thêm lỗi |
| Tích hợp thật (API Gemini thật, DB preview) | Có, **không** có CMS/xuất bản (cố ý không đăng web) |
| Người thật nghiệm thu | **Chưa**. Điểm 1–5 ở §4 là do AI (Claude) đọc và chấm, không phải chuyên gia dược/máy; không thay thế duyệt của người |

**Giới hạn:** tôi không kiểm chứng được sự thật ngoài kiến thức có sẵn của mô hình và nguồn trong hệ thống. Tìm kiếm web trong môi trường local là **mô phỏng** (nhật ký ghi "Tìm kiếm mô phỏng, môi trường local"), website thử có địa chỉ `.invalid` nên không đọc được trang thật, kho kiến thức trống. Nghĩa là các bài dược/GMP được viết **không có nguồn đã duyệt** — đúng kịch bản xấu nhất.

## 1. Cách chạy

- Khóa: tài khoản "Gemini – testZunP" (AiProvider #1) qua `AiProviderFactory`, không đọc/in khóa. Luồng: `AgentRunDispatcher::run` → `AgentRunOrchestrator` (an toàn → rủi ro → dàn ý → viết → chấm → sửa tối đa 3 vòng → nháp T1). Không đăng, không gọi CMS.
- Không có AiModelRoute nào trong DB nên đi đường "một Gemini"; **không đổi** route/cấu hình AI nào.
- Script tạm `storage/trial_run.php` (bọc lớp gọi API để giãn cách ≥ 6 giây giữa các lượt, đếm lượt gọi, dừng khi 3 lỗi nhà cung cấp liên tiếp). Còn nằm trong `storage/`, xóa khi không cần chạy lại.
- Dữ liệu thô: `storage/app/trial/*.json|html` (mỗi bài một JSON các bước + một HTML).
- Nhãn trong DB: website `TRIAL-GEMINI-LITE (pharma|machine|software)` (10 bài nháp Flash-Lite, trạng thái `needs_review`) và `TRIAL-GEMINI (…)` (lượt 3.6 Flash, gồm cả ca lỗi). Website `.invalid`, xóa website là xóa được hết.
- Giá: `TokenCostCalculator` nay có giá chính thức (nguồn aistudio.google.com/docs/pricing, lấy 12/11/2026). Tỉ giá cố định 25.500 đ/USD.

### Hai lượt chạy, và vì sao 3.6 Flash chỉ có 2 bài
- **Lượt 1 – `gemini-3.6-flash`**: chỉ **2/10** bài ra nháp (#1, #7). 8 bài còn lại hỏng với 429 (chính) và 503, sau 3 lần thử lại 2/5/12 giây. Nguyên nhân (do phiên điều phối cung cấp): project Google đang ở **Free tier**, 3.6 Flash chỉ **5 yêu cầu/phút, 20/ngày**, đã cạn. Tổng 14 lượt chạy/3.597 đ (kể cả các lượt chạy lại sau khi sửa lỗi DB).
- **Lượt 2 – `gemini-3.5-flash-lite`** (15 yêu cầu/phút, 500/ngày): chạy lại đủ 10 chủ đề, **10/10** ra nháp, 22 lượt gọi API, **1** lỗi 503 tạm thời và được thử lại thành công. Chưa bật billing nên **không** có số liệu 3.8 Flash.
- Hệ quả: so sánh 3.6 Flash ↔ Flash-Lite chỉ có **n=2** bài cùng chủ đề. Đọc là gợi ý, không phải kết luận thống kê.

## 2. Tổng hợp 10 bài (Gemini 3.5 Flash-Lite)

Token là tổng vào+ra (hệ thống chỉ ghi tổng, không tách, xem §7). Từ = đếm theo khoảng trắng của văn bản thật; cột "từ (hệ thống)" là số `agent_run_steps.data.words` dùng `str_word_count`, **sai với tiếng Việt** (xem §7).

| # | Ngành / ý định | Chủ đề | Token | Chi phí | Thời gian | Lượt API | Vòng sửa | Điểm/100 | Từ thật (hệ thống ghi) | Luật chưa đạt | Cờ rủi ro / chuyên gia |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Dược · thông tin | tiêu chuẩn GMP-WHO là gì | 5.495 | 191 đ | 11,8 s | 2 | 0 | 100 | 1.080 (1.673) | — | thấp / không |
| 2 | Dược · hướng dẫn | thẩm định quy trình sản xuất thuốc viên nén | 6.697 | 176 đ | 14,0 s | 2 | 0 | 90 | 763 (1.198) | LEN01 (P1), EVID01 (P1) | **cao** / cần chuyên gia |
| 3 | Dược · so sánh | so sánh GMP-WHO và EU-GMP | 7.071 | 200 đ | 15,2 s | 2 | 0 | 95 | 793 (1.255) | LEN01 (P1) | **cao** / cần chuyên gia |
| 4 | Dược · thương mại | dịch vụ tư vấn xây dựng nhà máy dược đạt GMP | 7.288 | 214 đ | 35,0 s | 3* | 0 | 93 | 854 (1.323) | KW01 (P1, P2) | **cao** / cần chuyên gia |
| 5 | Máy · thông tin | máy dập viên là gì và nguyên lý | 7.781 | 264 đ | 17,7 s | 3 | **1** | 70 → 93 | 942 (1.540) | v1: KW01 (P0); sau sửa: SRC01 (P1), KW01 (P2) | thấp / không |
| 6 | Máy · so sánh | máy dập viên xoay tròn vs tâm sai | 5.915 | 207 đ | 12,5 s | 2 | 0 | 90 | 1.119 (1.791) | KW01 (P1 ×2) | vừa / không |
| 7 | Máy · thương mại | báo giá máy dập viên công nghiệp | 5.351 | 180 đ | 11,7 s | 2 | 0 | 98 | 884 (1.392) | KW01 (P2) | vừa / không |
| 8 | PM · thông tin | marketing automation là gì | 4.951 | 153 đ | 10,4 s | 2 | 0 | 85 | 732 (1.142) | LEN01, META01, SRC01 (P1) | thấp / không |
| 9 | PM · hướng dẫn | thiết lập quy trình email tự động chăm sóc KH | 6.310 | 147 đ | 13,1 s | 2 | 0 | 90 | 590 (944) | LEN01, META01 (P1) | **cao** (xếp nhầm, xem §6) / cần chuyên gia |
| 10 | PM · so sánh | so sánh phần mềm marketing automation cho SME | 6.929 | 183 đ | 14,7 s | 2 | 0 | 95 | 919 (1.440) | KW01 (P1) | **cao** (xếp nhầm) / cần chuyên gia |

\* #4 gồm 1 lần 503 rồi thử lại thành công (nên 35 s).

| Chỉ số | Tổng | Trung bình | Min | Max |
|---|---|---|---|---|
| Chi phí / bài | 1.915 đ (≈ 0,075 USD) | **191 đ** (≈ 0,0075 USD) | 147 đ | 264 đ |
| Thời gian / bài | 156 s | 15,6 s | 10,4 s | 35 s |
| Token / bài | 63.788 | 6.379 | 4.951 | 7.781 |
| Lượt gọi API / bài | 22 | 2,2 | 2 | 3 |
| Từ thật / bài | 8.676 | 868 | 590 | 1.119 |

- **Qua cổng chất lượng (0 lỗi P0) ngay lần đầu: 9/10.** #5 trượt KW01 (P0), sửa 1 vòng lên 93 rồi đạt. Cuối cùng 10/10 có lỗi P0 = 0, nhưng **chỉ 1/10 (#1) sạch hoàn toàn** (không còn mục P1/P2).
- Kết quả cổng chỉ nói "đạt luật"; xem §4 để biết bài có tốt thật hay không. Điểm 90–100 đi kèm bài rất chung chung.
- JSON hợp lệ: 22/22 lượt Flash-Lite parse được, không cần repair. (Lượt 3.6 Flash có 1 ca "Phản hồi AI không phải JSON hợp lệ" trong lần thử đầu bài #1, sau 503; lần chạy lại thì hợp lệ — chỉ 1 mẫu.)
- Lỗi mạng/429: lượt Flash-Lite chỉ 1 lần 503; lượt 3.6 Flash: 8/10 bài chết vì 429/503 do hết hạn mức.

### So sánh 3.6 Flash ↔ Flash-Lite (cùng chủ đề, n=2)

| | #1 GMP-WHO là gì | #7 Báo giá máy dập viên |
|---|---|---|
| Flash-Lite | 5.495 tok, 191 đ, 11,8 s, điểm 100, 1.080 từ | 5.351 tok, 180 đ, 11,7 s, điểm 98, 884 từ |
| 3.6 Flash | 7.578 tok, 534 đ, 27,5 s, điểm 95 (SRC01), 1.054 từ, có bảng so sánh, h3 | 11.433 tok, 854 đ, 74,1 s, điểm 93 (SRC01, KW01), 1.106 từ |
| Chi phí 3.6/Lite | **2,8×** | **4,7×** |
| Nhận xét chất lượng | sâu hơn (bảng GMP-WHO/EU-GMP/PIC/S, có đối tượng áp dụng) nhưng vẫn khẳng định về EMA | **đưa số liệu thật sự** (30–120 triệu, 180–850 triệu đ) — **không kiểm chứng được**, có vẻ bịa; thêm lời "bảo trì trọn đời, hỗ trợ 24/7, chính hãng, CO/CQ" bịa cho doanh nghiệp |

Gợi ý: 3.6 Flash "chịu viết cụ thể" hơn, nên cũng bịa nhiều hơn; Flash-Lite viết chung chung an toàn nhưng rỗng. Hệ thống phạt cả hai như nhau (điểm 93–100) vì luật hiện tại không bắt được cả hai lỗi này.

## 3. Chi phí thật vs. POC cũ

POC (`reports/0-3_AI_POC_REPORT.md`) ghi bài dược 1.620 từ chỉ **3.120 token đầu ra**. Số thật của chạy này:
- 1 bài Flash-Lite hoàn chỉnh (dàn ý + viết) = **trung bình 6.379 token**, riêng bài dài 1.080 từ thật = 5.495 token (gồm dàn ý). Tiếng Việt ~ 3–4 token/từ nên 1.620 từ thật phải ≳ 5.000 token **chỉ riêng đầu ra**; 3.120 token cho 1.620 từ là không thể → xác nhận con số POC là mô phỏng/ước lượng.
- Ngoài ra POC dùng đơn vị "từ" của `str_word_count` (đếm sai tiếng Việt, phóng ~1,5×) hoặc ước lượng; nên "1.620 từ" có thể chỉ là ~1.050 từ thật.
- Bảng giá POC là Gemini 1.5/GPT-4o. Model đó đã ngừng; giá hiện tại ở §8.

## 4. Chấm chất lượng thủ công (1–5; 5 = tốt)

Người chấm: Claude (không phải chuyên gia ngành). Cột: **Ý** = đúng ý định tìm kiếm; **Sự** = độ chính xác sự thật (thấp = có khẳng định đáng ngờ/không kiểm chứng); **EEAT**; **Việt** = tiếng Việt tự nhiên; **Cấu** = heading/meta/FAQ/bảng; **LK** = liên kết nội bộ; **YT** = an toàn nội dung y tế/dược.

| # | Ý | Sự | EEAT | Việt | Cấu | LK | YT | Ghi chú (vấn đề cụ thể) |
|---|---|---|---|---|---|---|---|---|
| 1 | 4 | 3 | 2 | 4 | 3 | 1 | 4 | Nội dung đúng chung chung. Phần "GMP cơ bản vs GMP-WHO" lập luận mơ hồ (GMP-WHO là chuẩn khung, giấy chứng nhận do cơ quan quản lý quốc gia cấp). Không FAQ, không nguồn, có 2 ô "Ảnh minh họa do AI tạo" trống |
| 2 | 4 | 3 | 2 | 3 | 3 | 1 | 4 | Có lỗi dính chữ: **"vàqualified"**. Liệt kê thẩm định tiền đề/đồng thời/hồi cứu đúng khung nhưng không trích ICH Q7/WHO TRS, không số liệu cụ thể (cỡ mẻ, số mẻ). Hệ thống đã gắn cờ cần chuyên gia, đúng |
| 3 | 2 | 2 | 1 | 3 | 2 | 1 | 4 | Mục "Bảng so sánh chi tiết" **không có bảng** (chỉ 3 gạch đầu dòng). Nói EU-GMP "do EMA quản lý" (không chính xác: bộ quy tắc do Ủy ban châu Âu, thanh tra do cơ quan quốc gia, EMA điều phối). "Trái ngược hoặc nói cách khác…" lủng củng. Không điều khoản/số cụ thể |
| 4 | 4 | 3 | 2 | 4 | 4 | 1 | 4 | Đúng cấu trúc trang dịch vụ (layout, HVAC/MEP, SOP, thẩm định). Không số liệu, thời gian, giá, không mô tả kinh nghiệm thật của công ty. Title SEO thiếu từ khóa gần đầu (KW01) |
| 5 | 4 | 4 | 2 | 3 | 2 | 1 | 5 | Nguyên lý đúng. **Nhãn "H1:", "H2:" rò vào tiêu đề**; lỗi dính chữ **"bao gồmmotor"**, "định định sẵn". Dùng "tâm trục đơn/tâm quay" lẫn với "tâm sai" |
| 6 | 4 | 3 | 2 | 4 | 4 | 1 | 5 | Có bảng và ưu/nhược điểm. Mô tả "tâm sai" ≈ máy đơn chày hợp lý; con số "vài ngàn viên/giờ" và "hàng trăm ngàn" chung chung. Title KW01 |
| 7 | 2 | 4 | 1 | 3 | 3 | 1 | 5 | **Ý định thương mại hỏng:** bài "báo giá" không có **một con số giá**. Có ký tự lạ **"mỗiσ ca"**. Kết bằng "Chúng tôi tự hào là đơn vị…" (tự nhận danh nghĩa công ty không có thật) |
| 8 | 4 | 4 | 1 | 3 | 2 | 1 | 5 | Đúng nhưng chỉ ~730 từ, 0 danh sách, meta > 60 ký tự. Dùng "Tóm lại" (cụm đệm bị cấm trong POC), "một hệ thống này" sai ngữ pháp |
| 9 | 3 | 4 | 1 | 4 | 2 | 1 | 5 | "Hướng dẫn" nhưng không có ví dụ chuỗi email, số ngày giữa các mail, tiêu đề mẫu; ~590 từ (ngắn nhất) |
| 10 | 2 | 4 | 1 | 4 | 4 | 1 | 5 | Bài "so sánh phần mềm" **không nêu tên phần mềm nào** (chỉ 3 "nhóm công cụ"), không giá. Tốt cho tránh bịa nhưng không đúng ý định; 9 h3 làm cấu trúc đẹp bề ngoài |

Điểm trung bình tay (Flash-Lite): Ý 3,3 · Sự 3,4 · EEAT 1,5 · Việt 3,5 · Cấu 2,9 · LK 1,0 · YT 4,6.

Chấm tay 2 bài 3.6 Flash: #1 → Ý 5, Sự 3, EEAT 3, Việt 4, Cấu 4, LK 1, YT 4. #7 → Ý 4, **Sự 2** (giá + cam kết bịa), EEAT 2, Việt 4, Cấu 3, LK 1, YT 5.

Nhận định chung: bài đọc trôi chảy, hợp ngữ pháp, nhưng **mỏng, chung chung, không nguồn, không kinh nghiệm thật**, đúng ý định ở bài "là gì/hướng dẫn" và **hỏng ý định ở bài so sánh/báo giá**. Đây là bản nháp cần người viết lại phần dữ liệu, không phải bài đăng được.

## 5. Lỗi lặp lại (đa bài)

1. **Không bao giờ có liên kết nội bộ** (0/12) và **không FAQ** (11/12 thiếu; chỉ #4 có): thư viện link của website thử trống nên hệ thống không chèn được. *Đính chính:* bản đầu báo cáo này nói "ô ảnh giữ chỗ không có ảnh"; kiểm lại HTML thì sai: chú thích "Ảnh minh họa do AI tạo" đi kèm **ảnh AI thật** (thẻ `img` webp 96×72 px, lazy). Vấn đề thật là ảnh rất nhỏ, không phải ô trống.
2. **Lỗi dính chữ/ký tự lạ** (3/10): "vàqualified", "gồmmotor", "mỗiσ". Hệ thống đã có bộ lọc HTML nhưng không có luật bắt lỗi này. Khả năng cao do mô hình Lite chèn từ tiếng Anh/ký tự khác.
3. **Rò nhãn cấu trúc** "H1:/H2:" vào heading (#5).
4. **Bài thương mại/so sánh không cụ thể** (#7, #10, #3): mô hình né số liệu và tên thương hiệu để khỏi bịa → ý định thương mại thất bại. Mô hình mạnh hơn thì bịa số (3.6 Flash).
5. **Cụm đệm AI**: 6/10 bài có ít nhất 1 cụm trong danh sách đệm mở rộng (vd "đóng vai trò cốt lõi", "Trong bối cảnh", "vô cùng", "Tóm lại"); nhưng `AiPaddingFilter` **không được nối vào luật chấm** (chỉ khai báo trong một tính năng Filament) nên 0 bài bị phạt.
6. **Ngắn và rỗng hơn điểm số gợi ý**: LEN01 (min 800 từ) trượt 4/10 bài ở mức P1 (chỉ khuyến nghị) nên không bị chặn; điểm vẫn 85–95.
7. **KW01 (tiêu đề SEO chưa có từ khóa)**: 5/10 bài. Vòng sửa tự động chỉ chạy khi có P0, nên P1 KW01 tồn tại.

## 6. Phát hiện về luồng/hệ thống (cần sửa)

1. **DB preview lệch schema, mọi lần tạo phiên bản bài đều lỗi** (`no such column: approved_version_id`): cột có trong migration `2026_11_10_000002` nhưng không có trong `preview.sqlite` (migration được ghi nhận đã chạy). Bị nuốt thành "Lỗi hệ thống: QueryException" — **không log**, rất khó chẩn đoán. Tôi đã `ALTER TABLE articles ADD COLUMN approved_version_id` trên DB preview (đã sao lưu). Cần tìm migration nào làm mất cột và thêm `report($e)` ở nhánh `catch (Throwable)` của `AgentRunOrchestrator::run`.
2. **Chi phí model suy luận bị tính thiếu**: `GeminiAiProvider` chỉ đọc `candidatesTokenCount`, bỏ `thoughtsTokenCount` (Gemini tính tiền token suy luận như đầu ra). Đã sửa 1 dòng (cộng `thoughtsTokenCount`). Flash-Lite/3.6 Flash ở lượt này có thể ít thinking nên số không đổi nhiều, nhưng 3.8 Flash (suy luận) sẽ lệch nếu không sửa.
3. **Cơ chế thử lại với 429 quá yếu**: 2/5/12 giây không đủ cho hạn mức theo phút, và không đọc `Retry-After`. 8/10 bài lượt 1 chết dù chạy tuần tự. Cần giãn cách chủ động (hàng đợi + giới hạn tốc độ theo model) và đọc `Retry-After`.
4. **Phân loại rủi ro nâng cấp quá tay**: bài #9 (email tự động) và #10 (phần mềm marketing) bị nâng "cao" "theo cấu hình Trí tuệ Dược/Sức khỏe trẻ em" dù không liên quan. Tôi **chưa kiểm** vì sao Trí tuệ (Dược, Sức khỏe trẻ em) áp lên website thử không gắn Trí tuệ nào (nghi áp toàn cục). Hậu quả: cờ chuyên gia sai và thêm bước đọc web/tìm kiếm mô phỏng.
5. **Tìm kiếm web là mô phỏng ở local**, nên bài dược không có nguồn thật; EVID01 chỉ kiểm "đủ nguồn ở mức cả bài", không nối từng khẳng định với nguồn. Chỉ bài #2 bị EVID01 bắt; #3 (khẳng định EMA) không bị bắt.
6. **Đếm từ sai**: `data.words` dùng `str_word_count` (không hiểu dấu tiếng Việt), phóng ~1,5× (1.673 vs 1.080 thật). `ContentQualityChecker` (LEN01) đếm đúng; hai số lệch nhau trong giao diện.
7. **Không ghi tách token vào/ra** vào `agent_run_steps` (chỉ `tokens_used`), nên không tách được chi phí vào/ra thật; chi phí phải suy từ tổng.
8. **Điểm 0–100 che dấu chất lượng**: bài rỗng (#7, #10) vẫn đạt 95–98 vì luật chấm chỉ kiểm hình thức, không kiểm ý định/tính cụ thể/bịa.

## 7. Khuyến nghị

**Model theo vai trò** (dựa trên n=10 Flash-Lite + n=2 3.6 Flash; chưa thử 3.8 Flash, cần bật billing):

| Vai trò | Khuyến nghị | Lý do |
|---|---|---|
| Lập dàn ý/brief, gợi ý từ khóa, chấm sơ bộ | **3.5 Flash-Lite** | rẻ (~60 đ/bước), 10/10 JSON hợp lệ, nhanh |
| Viết bài tin tức/hướng dẫn chung (PM, máy) | Flash-Lite để nháp, **3.6 Flash** cho bài thương mại/so sánh | Lite né số liệu nên hỏng ý định; 3.6 viết cụ thể hơn (nhưng phải kiểm số liệu) |
| Bài dược/GMP/y tế | 3.6 Flash trở lên **+ nguồn đã duyệt trong kho + chuyên gia** | cả hai model đều không có nguồn; không được tin số liệu/tiêu chuẩn |
| Biên tập/chấm chất lượng | luật tất định + 3.8 Flash (suy luận) cho phần ý định/bịa — **chưa thử** | cần đo khi có billing |

**Không** dùng kết quả để quyết định "dùng Lite cho mọi thứ": chất lượng thấp ở bài thương mại/so sánh, EEAT thấp ở cả hai.

**Ước tính 1.000 bài/tháng** (mỗi bài ≈ dàn ý + viết; chưa tính sửa 10%, chưa tính chạy lại):
- Flash-Lite: 191 đ × 1.000 ≈ **191.000 đ/tháng** (≈ 7,5 USD), thêm ~10% sửa ≈ 210.000 đ.
- 3.6 Flash (n=2, trung bình 694 đ): ≈ **694.000 đ/tháng** (27 USD) theo giá hiện tại; **gấp đôi từ 01/01/2027** (vào 1,50 / ra 7,50 USD/1M) ≈ **1,4 triệu đ/tháng**.
- 3.8 Flash: giá cùng bậc 3.6 Flash trên bảng giá, **chưa đo token thật** (model suy luận thường tốn nhiều token suy luận hơn).
- Hạn mức Free tier hiện tại: Flash-Lite 500 yêu cầu/ngày ÷ 2,2 lượt/bài ≈ **227 bài/ngày** (đủ cho 1.000 bài/tháng nếu rải đều ~33 bài/ngày); 3.6 Flash 20 yêu cầu/ngày ≈ **9 bài/ngày** — **không đủ**, cần bật billing. Ngoài chi phí token, chưa tính ảnh, tìm kiếm web (Tavily), đọc website.
- Chi phí AI nhỏ so với chi phí người duyệt: điểm nghẽn thật là duyệt/viết lại bản nháp (§4), không phải tiền token.

**Việc cần sửa ở playbook/prompt/pipeline** (ưu tiên):
1. **Cổng chất lượng**: thêm luật kiểm "ý định đạt?" (bài báo giá phải có giá hoặc nói rõ "liên hệ" kèm khung; bài so sánh phải có tên đối tượng + bảng thật), luật bắt chữ dính/ký tự lạ/nhãn "H1:", nối `AiPaddingFilter` vào chấm, nâng LEN01 lên chặn cho bài thông tin.
2. **Prompt viết**: cấm tự nhận danh nghĩa công ty ("Chúng tôi tự hào…", "bảo trì trọn đời, 24/7") và số liệu không có nguồn; bắt buộc dùng nguồn từ kho khi có; bắt buộc FAQ và bảng khi ý định so sánh; cấm in nhãn "H1:/H2:".
3. **Vòng sửa** nên chạy cả khi chỉ còn P1 đáng kể (KW01, LEN01), có giới hạn vòng/ngân sách (đã có).
4. **Hạ tầng**: giới hạn tốc độ + `Retry-After`; log lỗi `Throwable` bị nuốt; sửa lệch schema preview; tách token vào/ra; đếm từ đúng; kiểm tra vì sao Trí tuệ dược/sức khỏe áp lên bài ngoài ngành.
5. Bật billing trước khi chạy đo 3.8 Flash và 3.6 Flash đủ 10 bài; chạy lại đo trên website có kho kiến thức/ảnh/link thật và **một lượt đánh giá của chuyên gia dược/máy** để có mức "người thật nghiệm thu".

## 8. Thay đổi đã làm (chưa commit)

- `app/Services/Ai/TokenCostCalculator.php`: thêm giá USD/1M token (vào/ra, văn bản, gói trả phí Standard): `gemini-3.5-flash-lite` 0,30/2,50; `gemini-3.6-flash` và `gemini-3.8-flash` 0,75/3,75 (**01/01/2027 tăng lên 1,50/7,50**); `gemini-3.1-pro-preview` 2,00/12,00 (prompt ≤ 200k token). Nguồn: https://aistudio.google.com/docs/pricing (ai.google.dev/gemini-api/docs/pricing chuyển hướng về đó), lấy 12/11/2026. Model không có trong bảng vẫn rơi về giá cao nhất của bảng (bảo thủ).
- `app/Services/Ai/GeminiAiProvider.php`: `completion_tokens` cộng thêm `thoughtsTokenCount`.
- `preview.sqlite`: thêm cột `articles.approved_version_id` (sao lưu: `wt/preview.sqlite.bak-pre-trial-20261112-0901`).
- Route/cấu hình AI: **không đổi**.
- Test `docker exec -e DB_DATABASE=:memory: seo-ag-s27 php artisan test tests/Feature/Agent tests/Unit`: **686 đạt, 7 lỗi** (đúng 7 lỗi nền; ví dụ `CampaignTest:177`, `ImagesLinksTest:254`), không thêm lỗi.

## 9. Đã sửa sau chạy thử (phiên sửa lỗi hệ thống, 12/11/2026, chưa commit)

| Mục §6 | Trạng thái | Thay đổi chính |
|---|---|---|
| 6.1 lỗi bị nuốt + DB lệch schema | Xong | `AgentRunOrchestrator::logUnexpected` ghi log có ngữ cảnh (run/bài/lớp lỗi/chỗ, không SQL/bind/nội dung). Nguyên nhân lệch: migration gốc `2026_11_10_000002` chưa từng đổi, nhưng DB preview được dựng từ nhánh cũ (cột `campaign_id` do migration keywords thêm sau `priority`) rồi migration bị đánh dấu đã chạy, nên không chạy lại. Thêm migration idempotent `2026_11_12_000001_ensure_articles_approved_version_id` (SQLite + MySQL, chỉ thêm cột + chỉ mục khi thiếu, không FK). **Chưa chạy trên preview** (theo yêu cầu không đụng `preview.sqlite`; preview đã có cột do lần ALTER tay trước đó nên migration sẽ là no-op) |
| 6.3 thử lại 429/503 | Xong phần cốt lõi | Đọc `Retry-After` (giây hoặc ngày giờ) và `RetryInfo.retryDelay` của Google; 429 hạn mức **ngày** (`PerDay`) → `AiQuotaExceeded`, không thử lại, báo rõ; có gợi ý chờ thì thử tới 6 lần, mỗi lần chờ ≥ gợi ý (+0–2 s), trần 90 s/lần và 240 s tổng; quá bậc 2/5/12 s thì nhân đôi có nhiễu ±20%. Lỗi không có gợi ý giữ nguyên 3 lần cũ. `AiRateGate` (cache dùng chung giữa tiến trình): cooldown theo (provider, model) khi nhà cung cấp bảo chờ + giãn cách tối thiểu cấu hình ở `config/ai_rate.php` (`AI_MIN_INTERVAL_SECONDS`, `per_model`), **mặc định tắt** vì không đoán hạn mức từng gói |
| 6.4 hạng rủi ro nâng sai | Xong | Nguyên nhân: `BrainAutoAttach` tự gắn Trí tuệ vào **website** khi giao bài, dựa trên cụm 2 từ trùng với mô tả Trí tuệ; "hướng dẫn", "chăm sóc" có trong mô tả Trí tuệ Dược/Sức khỏe trẻ em nên bài email/phần mềm bị gắn và nâng hạng, và mọi bài sau của website cũng chịu. Sửa: danh sách cụm chung chung bị bỏ khỏi so khớp (`BrainSuggester::GENERIC_BIGRAMS`). Kiểm trên 8 từ khóa: bài phần mềm/GMP không còn bị gợi ý; "người tiểu đường nên ăn gì", "chăm sóc da mặt tại nhà" vẫn khớp đúng Trí tuệ |
| 6.6 đếm từ | Xong | `App\Services\Content\VietnameseText` dùng chung cho `data.words` và LEN01 (tách khoảng trắng, bỏ dấu câu đứng riêng); bỏ `str_word_count` |
| 6.7 token vào/ra | Xong | Cột `agent_run_steps.prompt_tokens/completion_tokens` (migration `2026_11_12_000002`, nullable = không rõ); nhà cung cấp trả về hai số, bước ghi chúng; trang phiên hiện "(vào … · ra …)". Token suy luận đã tính vào đầu ra ở lượt trước. Chưa chạy migration trên MySQL thật (chỉ SQLite qua test) |
| §5/6.8 luật chấm | Xong (5 luật P1, không chặn) | `PAD01` cụm đệm (nối `AiPaddingFilter` + vài cụm), `TXT01` ký tự lạ/dính chữ, `LEAK01` nhãn "H1:/H2:", `IMG02` ô ảnh không có ảnh, `INT01` báo giá/so sánh thiếu cụ thể. Đăng ký trong `QualityRubric` |

### Chấm lại 12 bài đã lưu (không gọi API; `storage/regrade_trial.php`, DB `:memory:`)

| Bài | Điểm trước → sau | Cờ mới |
|---|---|---|
| Lite #1 GMP-WHO là gì | 100 → 100 | — |
| Lite #2 thẩm định viên nén | 90 → 80 | PAD01, TXT01 ("vàqualified") |
| Lite #3 GMP-WHO vs EU-GMP | 95 → 95 | — (có tên cụ thể nên INT01 không cờ; khẳng định sai về EMA luật không bắt được) |
| Lite #4 tư vấn nhà máy GMP | 93 → 93 | — |
| Lite #5 máy dập viên là gì | 98 → 88 | TXT01 ("gồmmotor"), LEAK01 ("H1:/H2:") |
| Lite #6 so sánh xoay tròn/tâm sai | 90 → 85 | PAD01 |
| Lite #7 báo giá máy dập viên | 93 → 78 | PAD01, TXT01 ("mỗiσ"), INT01 (không có giá) |
| Lite #8 marketing automation là gì* | 90 → 90 | — |
| Lite #9 email tự động | 90 → 85 | PAD01 |
| Lite #10 so sánh phần mềm SME | 95 → 85 | PAD01, INT01 (không tên phần mềm) |
| 3.6 Flash #1 | 100 → 95 | PAD01 |
| 3.6 Flash #7 | 93 → 93 | — (có khoảng giá nên INT01 không cờ; giá có thể bịa, luật không kiểm đúng/sai) |

\* file HTML của #8 là bản chạy lại (chạy chậm để quay màn hình) nên khác bản đã chấm tay ở §4.

Kết quả: **6/10 bài Flash-Lite có ≥ 1 cờ mới (11 cờ)**; cả 3 lỗi chữ đã biết (#2, #5, #7), nhãn "H2:" (#5) và cả hai bài "không cụ thể" (#7, #10) đều bị bắt. Không có luật mới nào chặn (P0). **Luật vẫn không bắt:** khẳng định sai thực tế (vd EMA), số liệu bịa (3.6 Flash #7), EEAT thấp, thiếu liên kết nội bộ khi kho link trống.

### Chưa làm / cần chủ dự án quyết
- Chưa bật giãn cách mặc định (`AI_MIN_INTERVAL_SECONDS`): cần biết hạn mức thật của gói (Flash-Lite 15/phút nếu vẫn Free tier).
- Hạn mức "ngày" phát hiện bằng `quotaId` chứa `PerDay` (Google) hoặc chữ "per day/daily" trong thông điệp; các nhà cung cấp khác chưa kiểm.
- Migration bù cột chưa chạy trên `preview.sqlite`; chạy `php artisan migrate` khi chủ dự án cho phép.
- `INT01` là heuristic (tên riêng viết hoa/bảng ≥ 3 hàng/giá kèm đơn vị tiền); có thể báo nhầm hoặc bỏ sót, nên giữ P1.
- Ảnh minh họa AI chỉ 96×72 px: cần quyết có đủ cho bài đăng hay không (ngoài phạm vi sửa lần này).

## 10. Đường CLI `agy` (cầu nối OpenAI-tương thích, gói Gemini Pro) — phép đo NHỎ, 12/11/2026

**Giới hạn thật của số liệu:** chỉ 1 bài chạy trọn luồng (#1, `agy-flash-high`) + 6 ô đo riêng bước viết (3 mức × 2 chủ đề, n=1/ô). Phép so sánh 3 đường dưới đây chỉ là **gợi ý trên n nhỏ**, không phải thống kê. Đã dùng **6 yêu cầu chat** cho phép đo (hạn mức cầu nối 18 → 24/100; trước đó lượt chạy 10 bài bị dừng sau bài #1 vì đốt hạn mức). Không tạo ảnh trong phép đo (`AGENT_IMAGES_ENABLED=false`), không thử lại 504, không sửa bài; dàn ý lấy từ bản Flash-Lite đã lưu cho cả ba mức để so cùng đầu vào. Tiền thật = **0 đ** (gói đăng ký); con số "đ" mà app hiện cho model lạ là giá mặc định cao nhất của bảng, không dùng.

### Thời gian và kết quả bước viết theo mức suy luận (cùng prompt thật của pipeline)

| Mức | Chủ đề | Kết quả | Thời gian | Từ thật | Điểm luật | Cờ | Token THẬT (`x_agy_usage`) vào / ra (suy luận) |
|---|---|---|---|---|---|---|---|
| low | #1 GMP-WHO | 200, JSON hợp lệ | **21,8 s** | 1.197 | 95 | SRC01 | 11.179 / 1.899 (0) |
| low | #8 marketing automation | 200, JSON hợp lệ | **18,5 s** | 908 | 95 | SRC01 | 11.166 / 1.473 (0) |
| medium | #1 | 200, JSON hợp lệ | **137,1 s** | 1.689 | 100 | — | 11.187 / 18.762 (15.916) |
| medium | #8 | 200, JSON hợp lệ | **143,7 s** | 1.380 | 95 | SRC01 | 11.165 / 21.706 (19.329) |
| high | #1 | **504** (bị cắt ở 180 s) | 180,3 s | — | — | — | — |
| high | #8 | **504** | 180,2 s | — | — | — | — |

- 504 của `high`: 2/2 trong phép đo; ở lượt chạy 10 bài trước đó 2/3 lần gọi viết cũng 504, lần thứ ba thành công sau 159 s (bài #1 mất **557 s**, 4 lần gọi chat + 3 ảnh). Tức `high` thành công ~1/5 lần ở bước viết với timeout cầu nối 180 s.
- JSON: cả 7 phản hồi 200 (6 ô + bài #1) parse được **không cần repair**, không rò ```` ``` ````/lời dẫn trong HTML. Riêng bước **dàn ý** của `high` rò nhãn "H2:" vào tiêu đề dàn ý (cầu nối/mô hình không loại; bài cuối đã sạch nhờ bước viết, nhưng cần luật LEAK01 và dặn trong prompt dàn ý).
- Ước lượng token của cầu nối thấp **~2,5–6 lần** so với token thật (vd bài #1 bước viết: ước 5.619, thật 33.533; `medium` ô #1 thật 29.949 trong đó 15.916 suy luận). Đừng dùng số "tokens" của app cho đường CLI; dùng `x_agy_usage`. Mỗi lần gọi tốn ~11k token vào (prompt dài do playbook/quy tắc).
- Luật mới (§9) chấm cả 7 bài CLI: chỉ bài #1 `high` bị `TXT01` đúng chỗ (ký tự Hán lọt vào chữ: "trụ柱"); các ô low/medium không cờ mới (chỉ SRC01 như mọi đường).

### Chấm tay nhanh (cùng thang 1–5; Ý / Sự / EEAT / Việt / Cấu; có lỗi của người chấm AI, không phải chuyên gia)

| Đường | #1 GMP-WHO | #8 marketing automation | Nhận xét |
|---|---|---|---|
| Flash-Lite API (1.080 / 781 từ) | 4/3/2/4/3 | 4/4/1/3/2 | gọn, chung chung, cụm đệm |
| low (CLI) | 4/3/2/4/3 | 4/4/1/3/3 | ≈ Flash-Lite về độ sâu; vẫn văn sáo ("vận hành trơn tru", "vượt trội"); không rò lỗi |
| medium (CLI) | 4/4/2/4/4 | 4/4/2/4/4 | **chi tiết hơn rõ** (IQ/OQ/PQ, HVAC, nguyên tắc một chiều; 14–15 mục h3); số liệu chung nhưng ít bịa; vẫn không nguồn, không liên kết nội bộ |
| high (CLI, bài #1 trọn luồng) | 4/3/2/4/4 | — | dài ~2.250 từ, tốt nhất nhưng lẫn ký tự Hán, và thường 504 |
| 3.6 Flash API (n=2) | 5/3/3/4/4 | (#7) 4/**2**/2/4/3 | cụ thể hơn nhưng bịa giá/cam kết; bị 429 do hạn mức Free |

### So sánh 3 đường (n nhỏ)

| | Flash-Lite API | 3.6 Flash API | CLI `agy` |
|---|---|---|---|
| Tỉ lệ ra bài | 10/10 | 2/10 (8 bài chết vì 429/503, hạn mức Free 5/phút, 20/ngày) | low 2/2, medium 2/2, high 0/2 trong phép đo (504) |
| Thời gian/bài | 15,6 s (10–35) | 28–74 s (2 bài) | low ~20 s (bước viết); medium ~140 s; high 159–557 s |
| Lượt gọi chat/bài | 2,2 | ~2–4 | 2 (low/medium) đến 4+ (high, có thử lại); thêm ~3 yêu cầu **ảnh** mỗi bài ngoài ngành dược (cũng ăn hạn mức ngày) |
| Lỗi hạ tầng | 1 lần 503/22 lượt | 8/10 bài 429/503 | `high` 504 thường xuyên; không 429/503 khác trong phép đo |
| Chi phí tiền thật | ~191 đ/bài | ~700 đ/bài | 0 đ (gói đăng ký), tốn hạn mức 100 yêu cầu/ngày (~6–7 yêu cầu/bài có ảnh) |
| Chất lượng | trung bình, rỗng | cụ thể hơn, hay bịa số | low ≈ Lite; medium tốt hơn Lite rõ; high tốt nhất nhưng không ổn định |

### Kết luận và khuyến nghị cho giai đoạn thử nghiệm
- **Viết bài (writer): `agy-flash-medium`** là mức duy nhất vừa thành công 2/2 vừa chất lượng hơn Flash-Lite rõ, nhưng **137–144 s sát trần 150 s và gần trần 180 s của cầu nối**: dễ 504 khi tải tăng (2 yêu cầu song song, prompt dài hơn, bài dài hơn). Cần `AI_HTTP_TIMEOUT=240` ở app và nên nâng timeout cầu nối (đề xuất 300 s; **chưa sửa/restart**, chờ chủ dự án đồng ý, sửa `~/.config/agy-bridge/config.json`). `agy-flash-low` (20 s) dùng được cho nháp nhanh nhưng chất lượng chỉ ngang Flash-Lite. `agy-flash-high`: **không dùng cho bước viết** (504 gần như luôn ở timeout 180 s).
- **Dàn ý (strategist) và chấm/sửa (editor): `agy-flash-low`** (~20–30 s, ít suy luận, JSON hợp lệ); lưu ý dàn ý của `high` rò "H2:".
- **Dự phòng:** Gemini 3.5 Flash-Lite (API) khi cầu nối 504/dừng hoặc hết 100 yêu cầu/ngày (đặt route dự phòng ở Cấu hình AI). Cầu nối dừng thì kết nối bị từ chối → app coi là lỗi tạm thời, thử lại 3 lần (2/5/12 s) rồi báo "Nhà cung cấp AI lỗi tạm thời… không thay bằng AI giả lập", phiên chuyển `failed`, không mất dữ liệu (đã thấy ở lần đo 09:5x khi cầu nối chưa chạy, và trong các ca 504).
- **Hạn mức:** 100 yêu cầu/ngày chia cho chat + ảnh. Bài có ảnh ≈ 2 chat + 3 ảnh = 5 yêu cầu → ~20 bài/ngày tối đa; không ảnh ≈ 2 → ~50 bài/ngày. Mỗi lần thử lại cũng ăn hạn mức, nên với CLI chỉ thử lại 504 tối đa 1 lần ở chế độ thử nghiệm (chưa nối vào code; hiện chính sách là 3 lần cho lỗi tạm thời không có gợi ý chờ).
- **Nếu timeout 300 s vẫn 504:** chia bài thành từng phần (dàn ý → viết 2–3 nhóm mục → ghép), mỗi lần gọi ~50–70 s ở `medium`. Ước lượng, **chưa làm**.
- Rủi ro vận hành: cầu nối chạy nền bằng `nohup` (không tự khởi động lại), một tiến trình Python đơn, concurrency 2, token cầu nối là bí mật dùng chung, ước lượng token sai nhiều (không tin được báo cáo chi phí/ngân sách trong app), tạo ảnh tốn ~56 s và ~45k token vào mỗi ảnh.
- Phát hiện kèm: `AI_HTTP_TIMEOUT` (mới, mặc định 90) cho phép chờ lâu hơn; code ghi bước chạy được khi chưa có migration cột token (tránh phiên kẹt "đang chạy" khi preview chưa migrate).

## 11. Lượt thử bước 5 (12/11/2026): cấu hình mới, định tuyến theo vai trò, có ảnh

Cấu hình: writer `agy-flash-medium`, strategist/editor `agy-flash-low` (dự phòng Gemini 3.5 Flash-Lite), designer `agy-image`; cầu nối timeout 300 s, `AI_HTTP_TIMEOUT=240`; 504 thử lại tối đa 1 lần. Chủ đề "marketing automation là gì" trên website thử `trial-gemini-cli-software.invalid` (không gắn Trí tuệ). n = 1–2 bài/ô, chỉ là gợi ý, không phải thống kê. Dữ liệu thử, không đăng web thật.

| Lượt | Phiên | Kết quả | Thời gian | Điểm | Ghi chú |
|---|---|---|---|---|---|
| A | #103 | ready_for_review | 51 s | 95 | Website #6 đang gắn Trí tuệ Dược + Sức khỏe trẻ em (dữ liệu cũ) nên rủi ro "cao", bỏ ảnh đúng luật (`medical`). Không phải lỗi mã. |
| B | #106 | ready_for_review | 143 s | 90 | Bước ảnh lỗi: `OpenAiCompatImageGenerator` dùng `validateUrl` nên chặn 172.17.0.1 (SSRF). **Lỗi thật của mã hôm nay, đã sửa** sang `validateAiBaseUrl` (dùng danh sách cho phép Base URL nội bộ). |
| C | #107 | ready_for_review | 419 s | 95 | Đủ luồng, 3 ảnh "AI tạo" qua `agy-image`. |

Lượt C: dàn ý `agy-flash-low` 1.734 token (681 vào / 1.053 ra); viết `agy-flash-medium` 4.901 token (2.007 / 2.894). Chi phí app ghi: dàn ý ~366 đ, viết ~1.014 đ, ảnh 3.000 đ (1.000 đ/ảnh là giá ước tính cấu hình, chưa đo thật). Token do cầu nối ước lượng, thấp hơn thật (xem §10). Thời gian chủ yếu là 3 ảnh (~56 s/ảnh); bước viết `medium` 126–140 s, dưới trần 240 s. Hạn mức cầu nối dùng 24 → 33/100 sau cả 3 lượt (6 chat/ảnh gồm cả lượt A).

Chưa làm: chấm chất lượng nội dung bởi người, kiểm ảnh bằng mắt, test hồi quy cho lỗi SSRF ảnh, trang "Theo model" vẫn dồn chi phí bước dàn ý vào model bước viết.
