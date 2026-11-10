# Đánh giá trải nghiệm theo kịch bản và kế hoạch cải thiện

Ngày: 11/11/2026. Người đánh giá: đóng vai nhân viên SEO, content, marketing và quản lý. Nhánh `feat/m09/ag-s27/dev2/article-workspace`.

## 0. Mức bằng chứng (đọc trước)

| Cách kiểm | Đã làm | Chưa làm |
|---|---|---|
| Đi giao diện thật (bản xem thử cục bộ, AI giả lập) | Từ khóa, gom cụm, kế hoạch, giao đội AI, bài viết (tab, phiên bản), duyệt, Pipeline, Vai trò, Xây kho | Mọi thao tác trên website thật |
| Test tự động (SQLite 1360 đạt; MariaDB chỉ lỗi cũ của Dev1) | Toàn bộ luồng chính, quyền, tự duyệt, cổng, ảnh, tìm nguồn, số liệu (đều bằng AI giả, mạng giả) | — |
| **AI thật, dịch vụ ngoài thật** | **Chưa**: chưa gọi model thật, Tavily, DataForSEO, API ảnh | Chất lượng bài, độ tin cậy nguồn, chi phí thật |

Vì vậy mọi nhận xét về **chất lượng nội dung do AI viết** dưới đây là suy luận từ thiết kế và mã, **chưa được chứng minh**. Phần còn lại (luồng, liên kết, quyền, an toàn, hiển thị) đã được kiểm.

## 1. Câu trả lời cho các câu hỏi của bạn

| Câu hỏi | Trả lời hiện tại | Bằng chứng |
|---|---|---|
| Có thực sự dùng AI giải được việc của đội SEO/marketing/content? | **Một phần, đúng hướng**: từ khóa → cụm → kế hoạch → viết → kiểm → duyệt chạy liền mạch, có tự chuẩn bị kho và tạo ảnh. Giá trị thật chỉ biết khi chạy AI thật. | UI + test, AI giả |
| Hiệu quả, có giá trị? | Giảm các bước thủ công rõ rệt (không còn tự gắn Trí tuệ, tự điền hồ sơ, tự đọc website, gợi ý kế hoạch). Chưa đo thời gian tiết kiệm thật. | UI + test |
| Nội dung đảm bảo từ nguồn tin cậy và vốn có? | Có cơ chế: kho + website công ty + tìm web có nhãn tin cậy theo luật, mục rút ra phải có đoạn nguyên văn, luật `SRC01` gắn cờ nguồn AI tự nêu, mỗi bài hiện nguồn. **Chưa chứng minh bằng nguồn thật.** | Test với mạng giả |
| Mang được bản sắc thương hiệu? | Hồ sơ doanh nghiệp tự điền từ website, từ cấm kiểm bằng luật (BAN01), hồ sơ agent đi vào prompt. **Chưa có nơi nào cho người xem "bài này đã bám giọng và thông điệp thương hiệu chưa".** | Mã + test |
| Mang đặc tính lĩnh vực? | Trí tuệ theo lĩnh vực tự gắn theo chủ đề, nâng hạng rủi ro, bắt buộc nguồn. Tương tự: chưa có chỗ xem "đã tuân thủ quy tắc lĩnh vực nào". | UI + test |
| Giảm tải khối lượng công việc? | Có cho chuỗi sản xuất. Còn thiếu: khởi tạo website một lần, chạy tự động liên tục (cần chạy nền). | UI |
| Quản lý nhìn nhận được kết quả và can thiệp đúng giai đoạn? | Nhìn được (phiên, Pipeline đang chạy, chi phí, so sánh phiên bản). **Can thiệp giữa chừng còn yếu**: chỉ có giao lại/duyệt/từ chối, chưa có "chỉ dẫn thêm" cho một bài. | UI |
| Khó dùng không? | Dễ hơn bản đầu (bỏ cổng chặn, tự gắn, dải "vai trò của trang", menu theo chuỗi). Còn rối ở: hai chỗ cùng nói "kế hoạch" (Kế hoạch nội dung, Lịch tuần), menu Kho trí tuệ và Xây kho tách rời. | UI |

## 2. Kịch bản sử dụng (đầu vào → cách dùng → kết quả)

Mỗi kịch bản ghi: **Đầu vào** nhân viên có, **Cách làm** trong hệ thống, **Kết quả**, **Đánh giá**.

### K1. SEO: tăng lưu lượng cho nhóm "tiểu đường" (website mới)
- Đầu vào: tên website, một CSV từ khóa (hoặc không có), mục tiêu miệng.
- Cách làm: Website kết nối → Từ khóa (nhập CSV, Khảo sát AI, Lấy lượt tìm thật) → Gom cụm → Kế hoạch nội dung (Đề xuất theo luật, Thêm vào kế hoạch).
- Kết quả: kho từ khóa có cụm, kế hoạch xếp theo lượt tìm và độ phủ cụm; bài chờ viết.
- Đánh giá: **Tốt** ở xếp thứ tự và nhãn nguồn số liệu. **Thiếu**: chưa có bước "khởi tạo website một lần" nên người dùng tự đi 6 trang; chưa có số lượt tìm thật cho tới khi cấu hình DataForSEO.

### K2. Content: viết 3 bài đã vào kế hoạch
- Cách làm: Bài viết → mở bài → thấy "AI sẽ tự chuẩn bị" → Giao đội AI (vai writer giao được) → theo dõi tab Hoạt động → đọc phiên bản → gửi duyệt.
- Kết quả: bản nháp kèm nguồn, ảnh, điểm hình thức, so sánh phiên bản.
- Đánh giá: **Tốt** về luồng và độ minh bạch. **Thiếu**: không có chỗ viết "chỉ dẫn thêm" cho bài (góc nhìn, đối tượng, điều cần nhấn); muốn sửa hướng phải giao lại cả bài.

### K3. Trưởng nhóm duyệt: hàng chờ, bài y tế
- Cách làm: Bài viết (tab Chờ duyệt) → Xem phiên bản → kiểm chất lượng, nguồn, nhãn "chờ chuyên gia" → Duyệt/Từ chối → Xếp lịch.
- Kết quả: bài đã duyệt, lượt đăng có lịch.
- Đánh giá: **Tốt**. Điểm "100/100" đã đổi thành "điểm hình thức" kèm cảnh báo. Lỗi cũ "2 nút Chờ duyệt" đã sửa. **Thiếu**: người duyệt không có bảng "bài đã bám thương hiệu và quy tắc lĩnh vực chưa".

### K4. Quản lý: giao chiến dịch "Toàn quyền" và theo dõi
- Cách làm: Chiến dịch → chọn Toàn quyền (xác nhận chính sách) → xem Pipeline (Đang chạy, Văn phòng AI), Chi phí AI, tổng quan.
- Kết quả: bài thường tự viết và tự duyệt; bài sức khỏe chờ chuyên gia; bản nháp đẩy lên CMS ở bậc T1.
- Đánh giá: **Khá**. **Thiếu**: chưa có chạy nền liên tục (chiến dịch chỉ chạy theo nhịp 15 phút đồng bộ), chưa có "bảng điều khiển hôm nay": việc đang chạy, chờ người, cần chuyên gia, gợi ý kho.

### K5. Xây kho tri thức (người phụ trách kho)
- Cách làm: Xây kho từ website (đọc trang công ty) → Khám phá nguồn trên web (nhập chủ đề, chọn nguồn tin cậy, thêm) → xem Gợi ý cho kho.
- Kết quả: mục kiến thức tự nhận từ nguồn tin cậy, mục chưa rõ chờ duyệt một lần.
- Đánh giá: **Đúng hướng NotebookLM** (tìm nguồn, nhãn tin cậy + lý do, thêm một cú bấm). Chưa chạy thật. **Thiếu**: chưa nối với "khi viết bài thiếu nguồn thì gợi ý ngay trong bài".

### K6. Làm mới bài cũ từ Audit
- Cách làm: Audit (Quét thật) → Tạo đề xuất làm mới → Chấp nhận → bài kind=refresh → giao đội AI (sửa một phần) → So với bản trước.
- Đánh giá: **Tốt về thiết kế, mới kiểm bằng test**. Chưa quét website thật.

### K7. Sự cố: AI lỗi, hết ngân sách, dừng khẩn cấp
- Đánh giá: các nhánh chặn đã có test (hết ngân sách trước bước viết, lỗi nhà cung cấp, dừng khẩn cấp); thông báo nêu lý do. **Thiếu**: thử lại chỉ có giao lại tay.

### K8. Vai và quyền
- Writer: tạo/sửa bài, giao đội AI. Approver: thêm duyệt, xếp lịch, đổi chế độ chiến dịch. Admin: sửa hồ sơ agent, chính sách.
- Đánh giá: **Hợp lý**, đã có test.

## 3. Các điểm yếu và kế hoạch cải thiện

Trạng thái: ✅ đã làm (kể cả đợt làm đêm 10–11/11) · ⏭ cần điều kiện bên ngoài (khóa dịch vụ, chạy nền của Dev1, website thật).

| # | Vấn đề | Việc làm | Trạng thái |
|---|---|---|---|
| 1 | Cổng chặn, cấu hình thủ công | Tự gắn Trí tuệ, tự đọc website, tự điền hồ sơ, bỏ cổng | ✅ |
| 2 | Toàn quyền bị khóa | Mở khóa, tự duyệt có điều kiện, xác nhận chính sách | ✅ |
| 3 | Không tạo ảnh | Tạo ảnh có luật, trần chi phí, nhãn AI tạo | ✅ (chưa chạy API thật) |
| 4 | Lịch đăng trỏ trang cũ | Mọi liên kết bài viết về không gian bài viết | ✅ |
| 5 | Trang rời rạc, không rõ vai trò | Dải "lấy từ → để làm gì → kết quả đi tới", menu theo chuỗi | ✅ |
| 6 | Pipeline không trực quan | Sơ đồ luồng, đang chạy, văn phòng AI, liên kết bản ghi | ✅ |
| 7 | Agent không tùy chỉnh được | Agent Studio: persona, quy tắc, bài học, kỹ năng, vị trí trong luồng | ✅ |
| 8 | Tìm nguồn ngoài | Tavily + nhãn tin cậy theo luật + NotebookLM-style | ✅ (cần khóa) ⏭ |
| 9 | Lượt tìm thật | DataForSEO + nhãn nguồn + tổng cụm | ✅ (cần khóa) ⏭ |
| 10 | Chạy liên tục tự động từ khảo sát đến viết | **Autopilot** theo chế độ Toàn quyền: giữ hàng chờ kế hoạch đủ đầy, tự khảo sát khi thiếu từ khóa | ✅ |
| 11 | Can thiệp giữa chừng yếu | **Chỉ dẫn cho đội AI** ở từng bài (đi vào dàn ý và bản viết), "Chạy lại với chỉ dẫn" khi bài bị giữ/từ chối | ✅ |
| 12 | Báo cáo kết quả từng loại luồng | Thẻ "Báo cáo phiên" ở trang phiên (khảo sát, xây kho, viết bài) tóm tắt đầu vào/kết quả và liên kết bản ghi | ✅ |
| 13 | Không thấy "bài có bám thương hiệu/lĩnh vực không" | Bảng "Bản sắc và quy tắc lĩnh vực" ở trang phiên bản (thông điệp đã nêu, từ cấm, Trí tuệ áp dụng, quy tắc đã tuân thủ) bằng luật | ✅ |
| 14 | Quản lý thiếu bảng "hôm nay" | Widget tổng quan: đang chạy, chờ người, cần chuyên gia, gợi ý kho, chi phí hôm nay | ✅ |
| 15 | Khởi tạo website mất nhiều bước | **Khởi tạo website** một nút (xây kho + hồ sơ + gắn Trí tuệ + khảo sát từ khóa + gợi ý kế hoạch) | ✅ |
| 16 | Đo hiệu quả từng bài | Search Console theo bài (cần OAuth) | ⏭ |
| 17 | Chạy nền thật (hàng đợi) | Job nền cho chạy dài, thử lại, tạm dừng/chạy tiếp | ⏭ (Dev1) |
| 18 | Chất lượng AI thật chưa đo | Chạy thử có đo token và chi phí trên một website thật | ⏭ |

## 4. Quyết định đã chọn thay chủ dự án trong đợt làm đêm

- Tìm kiếm web: Tavily. Lượt tìm: DataForSEO. Cả hai đặt khóa trong `.env`; chưa có khóa thì hiện "chưa cấu hình" kèm cách bật.
- Nguồn tin cậy do **luật** quyết định (danh sách tên miền theo lĩnh vực + Sổ nguồn); mục từ nguồn tin cậy có đoạn nguyên văn hợp lệ được tự nhận kể cả lĩnh vực sức khỏe; nguồn chưa rõ chỉ ở dạng đề xuất.
- Bài sức khỏe hoặc rủi ro cao **không tự duyệt** ở chế độ Toàn quyền (chờ người có chuyên môn); đây là điểm giữ lại duy nhất và cần chủ dự án xác nhận nếu muốn bỏ.
- Chế độ Toàn quyền chỉ tạo bản nháp trên CMS ở bậc T1; không viết mã tự đăng công khai.
- Hồ sơ agent do admin đặt đi vào prompt nhưng không ghi đè quy tắc an toàn.

## 5. Cần Dev1 rà trước khi vào nhánh chính

Migration: `2026_11_20_000001` (job_type), `2026_11_21_000001` (nguồn gốc/tầng kho), `2026_11_22_000001` (hồ sơ agent), `2026_11_23_000001` (nguồn số liệu từ khóa), `2026_11_24_000001` (chỉ dẫn cho đội AI ở từng bài). Lõi: `AgentRunOrchestrator` (bước nghiên cứu, tự chuẩn bị kiến thức, tìm web, tạo ảnh, hồ sơ agent), `AbstractLlmProvider` (sửa một phần, hồ sơ vai trò), luật `SRC01`, tự duyệt qua `ReviewWorkflow` bằng tài khoản hệ thống, `SafePageFetcher` ở chế độ tải tên miền ngoài.

## 6. Nhập vai thật trên giao diện (11/11/2026, bản xem thử cục bộ, AI giả lập)

Đã làm thật, từng bước, với ba vai đăng nhập bằng tài khoản mẫu: **writer** (nhân viên content), **approver** (trưởng nhóm duyệt) và **admin** (quản lý). Tình huống: website dược ADDP, bài "chỉ số đường huyết bình thường" (chủ đề sức khỏe), sếp dặn viết cho người trên 50 tuổi mới chẩn đoán.

| Bước | Vai | Kết quả |
|---|---|---|
| Vào trang chủ | writer | Thấy "Đội AI hôm nay" và việc của mình. Menu gọn, đủ việc. |
| Mở bài đã lên kế hoạch | writer | Thấy khối "AI sẽ tự chuẩn bị" (rủi ro cao, kho 0 mục, hồ sơ 0%, Trí tuệ đã tự gắn kèm Hoàn tác). |
| Nhập "Chỉ dẫn cho đội AI" | writer | Lưu được, hiện ngay ở tab Tổng quan. |
| Giao đội AI | writer | **Được** (đúng yêu cầu). Phiên chạy xong: tự gắn Trí tuệ, thử đọc website, thử tìm web, dàn ý, viết, sửa 1 vòng, tạo ảnh, gắn nhãn chuyên gia. |
| Đọc bản nháp | approver | Một trang đủ: chất lượng, bản sắc/quy tắc lĩnh vực, thẻ SEO, nguồn, ảnh, lịch sử. |
| Duyệt kèm ghi chú | approver | Được. |
| Xếp lịch đăng | approver | Được, nhưng bộ chọn ngày giờ khó dùng (xem dưới). |
| Lịch xuất bản, Lịch tuần | approver | Bấm bài mở đúng **trang bài mới ở tab Xuất bản** (lỗi cũ đã hết). |

**Lỗi và điểm sai phát hiện khi nhập vai, đã sửa ngay (có test)**
1. Gợi ý Trí tuệ sai ("Sức khỏe trẻ em" cho bài đường huyết người lớn) vì trùng hai từ rời "đường", "thường": nay chỉ gợi ý khi trùng cụm hai từ liền nhau.
2. Bài sức khỏe vẫn được tạo 3 ảnh AI vì từ "đường huyết" chưa nằm trong danh sách rủi ro cao: đã thêm thuật ngữ (đái tháo đường, đường huyết, HbA1c, insulin, cholesterol...), và Trí tuệ rủi ro cao cũng được coi là sức khỏe.
3. Câu cảnh báo nói sai: "AI đã tự bổ sung kiến thức từ website" trong khi lượt đọc website thất bại và mục kiến thức đưa cho AI là 0: đã đổi thành nội dung đúng sự thật.
4. Nhãn "Chờ chuyên gia xem lại" vẫn còn sau khi bài đã duyệt: nay đổi thành "Chủ đề cần chuyên môn · xem ghi chú duyệt".
5. Chỉ dẫn của người dùng không thấy được đã áp dụng: nay bước dàn ý và bước viết ghi "Đã áp dụng chỉ dẫn thêm của người phụ trách".

**Điểm khó dùng còn mở**
- Bộ chọn ngày giờ xếp lịch: hiển thị "Th11 12, 2026 00:00" (không phải định dạng Việt), giờ mặc định 00:00 và nhập giờ khó; người dùng dễ xếp nhầm nửa đêm.
- ~~Menu của writer còn "Thành phần UI"~~ và ~~khối "Đội AI hôm nay" hiện số chi phí cho writer~~: đã sửa (ẩn khỏi menu nhân viên; writer chỉ thấy đang chạy, chờ duyệt, chờ chuyên gia).
- ~~Bộ chọn ngày giờ xếp lịch~~: đã sửa (cả nút Xếp lịch và Đổi giờ đăng): hiển thị dd/mm/yyyy HH:mm, tuần bắt đầu thứ Hai, bước 5 phút, mặc định 9 giờ sáng ngày mai (giờ VN), không chọn được ngày đã qua. Sửa nằm trong `ReviewActions` và `RescheduleAction`, Dev1 cần biết khi rà.
- Duyệt bài sức khỏe không có chỗ ghi "tôi là người có chuyên môn xác nhận": chỉ có ô ghi chú tự do.
- Đã thử đọc website nhưng mạng của bản xem thử không ra ngoài nên không đánh giá được việc tự bổ sung kiến thức thật; nội dung bài do AI giả lập (mẫu 925 từ), **không đánh giá được văn chất lượng**.
