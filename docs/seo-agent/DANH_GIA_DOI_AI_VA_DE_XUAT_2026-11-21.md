# Đánh giá đội AI và đợt sửa 21/11/2026

Nguồn phản hồi: 8 điểm người dùng nêu tối 20/11. Nhánh `feat/M09/ag-s28/dev2/quan-ly-seo-dieu-phoi` (cắt từ `origin/main` 07c5a88). **Chưa commit, chưa push, chưa PR.**

Mức kiểm chứng dùng thang của AGENTS.md: **(1)** đã viết code → **(2)** test giả lập đạt → **(3)** thử trên staging → **(4)** người thật nghiệm thu. Mọi mục dưới đây mới ở mức (1)–(2). Chưa có mục nào ở mức (3) hoặc (4); chưa chạy với model thật nào trong đợt này.

## 1. Tóm tắt từng điểm

| # | Vấn đề | Nguyên nhân tìm được | Đã làm | Mức |
|---|---|---|---|---|
| 1 | Giao làm lại "chỉ sửa ảnh" nhưng vẫn chạy cả viết; ảnh "có chữ" không có chữ | (a) `RedoScope::resolve` luôn thêm "bài viết" vào mọi phạm vi. (b) Ba chỗ cấm chữ cùng lúc: khung `RULES` nối cuối prompt ảnh, `ImageDesigner::designPrompt` cấm chữ, `safePrompt` gỡ cụm chữ; lời dặn của người duyệt lại nối SAU khung cấm nên bị đè | Phạm vi chỉ gồm phần được nêu; "chỉ…" là giới hạn cứng, "giữ nguyên bài" loại bài viết. Chế độ mới `executeWithoutWriting`: không dàn ý, không gọi AI viết, không chấm lại chữ, bản mới giữ chữ nguyên từng ký tự, chỉ đổi ảnh. Ảnh có chữ: họa viên trả `overlay_text` (1–3 cụm ≤ 40 ký tự), khung an toàn đổi sang "chỉ cho phép đúng các cụm này"; mọi điều cấm khác (người thật, logo, trước-sau…) giữ nguyên | (2) |
| 2 | Viết chỉ dẫn "chỉ tạo ảnh" cho bài mới chạy mà phiên vẫn làm từ đầu | Chỉ lời nhắn của nút Giao làm lại đi qua điều phối; chỉ dẫn lưu theo bài (`articles.guidance`) chỉ là văn bản gắn vào prompt | Chỉ dẫn của bài nêu phạm vi rõ ("chỉ tạo ảnh", "giữ nguyên bài viết") cũng được điều phối đọc, điều kiện: bài đã có bản để giữ. Chỉ dẫn hướng nội dung bình thường ("thêm ảnh minh họa dễ hiểu") không bị coi là giới hạn phạm vi | (2) |
| 3 | Quản lý SEO yếu, không điều phối | Thứ tự bước cố định trong mã; thiếu tài liệu chỉ bị phát hiện SAU khi viết (cổng chấm) | Quản lý SEO nay (a) đọc cả chỉ dẫn lẫn lời nhắn, chốt phạm vi và dặn từng người; (b) **soát tài liệu từng mục dàn ý trước khi viết** và ra lệnh Người nghiên cứu tìm ngay cho mục thiếu (chủ đề rủi ro vừa/cao, có tìm kiếm web thật, tối đa 2 mục, trong trần chi phí); (c) ghi lệnh vào nhật ký dưới tên "Quản lý SEO". Kỹ năng mới `manager/run-supervision` (bảng tín hiệu → hành động) | (2), riêng (b) mới test với Tavily giả |
| 4 | Nạp kho 0 nguồn | **Không phải do model.** Dữ liệu 110 phiên: kết quả tìm có nhưng không nguồn nào thuộc danh sách tin cậy; trang chặn tải (HTTP 403 mayoclinic, moh.gov.vn không tải được); nội dung đã có sẵn trong kho; một số lượt bị chống-trùng 10 phút. Nhật ký cũ chỉ ghi "0 nguồn" nên người xem không thể biết | `KbIntake` chẩn đoán từng lượt (không có kết quả / không có nguồn tin cậy / lỗi tải / đã có trong kho / quá ngắn / không rút được mục), ghi lý do thật vào bước, thử lại **một lần** với cách diễn đạt khác khi còn cứu được; "đã có trong kho" hiện màu xanh vì không phải sự cố | (2) |
| 5 | Thiếu vai trò | Danh sách chỉ có 9 vai trò; nghiên cứu, lập kế hoạch, phân tích tệp có code nhưng không có tên vai trò | Thêm 4 vai trò (mục 3). Các bước nghiên cứu trong nhật ký nay ghi tên "Người nghiên cứu" thay vì "Hệ thống" | (2) |
| 6 | Nút Giao làm lại ở trang chi tiết nội dung | Trang bản bài (`ViewArticleVersion`) chưa có | Thêm nút dùng chung hộp thoại với trang bài và bảng Bài viết, cùng điều kiện hiển thị và quyền | (1)–(2) mới kiểm cú pháp và test chung; chưa bấm thử trong trình duyệt |
| 7 | Huấn luyện khó dùng, kỹ năng còn cơ bản | Persona/quy tắc mặc định chỉ để hiển thị, **không đi vào prompt** cho tới khi người dùng tự lưu tùy chỉnh; kỹ năng viết/biên tập/ảnh chung chung | Xem mục 4 | (1)–(2) |
| 8 | Đề xuất vai trò | | Mục 3 và 5 | |

## 2. Chi tiết kỹ thuật cần Dev1 rà

- `app/Services/Agent/Run/RedoScope.php`: `resolve()` thêm tham số `$hasPrevious`; `exclusive()`, `mentionsScope()`, `skipsWriting()` mới. **Đổi hành vi:** trước đây `["images"]` luôn thành `["write","images"]`; nay chỉ ảnh. Đổi dàn ý vẫn kéo theo viết lại.
- `AgentRunOrchestrator`: `executeWithoutWriting()`, `reviewEvidenceAgainstOutline()`, `intakeWhy()`, `applyImageNote()` viết lại thứ tự nối khung an toàn. Bước nghiên cứu đổi vai từ `system` sang `researcher`.
- `ImagePromptBuilder`: `allowText`, `rulesWithText`, `cleanTexts`, `wantsText`, `quotedTexts`. **Điểm an toàn cần xem:** cụm chữ do người duyệt gõ đi qua `clean()` (bỏ thẻ, từ cấm của thương hiệu); cụm chữ do họa viên AI đề xuất bị cắt 3 cụm × 48 ký tự và bị cấm bịa số liệu trong prompt, nhưng **không có bộ kiểm tự động** chữ trong ảnh đã sinh (model ảnh hay sai dấu tiếng Việt): người duyệt cần xem.
- `KbIntake` (mới), `EditorialReviewer::review(..., $skill)`, `AbstractLlmProvider::playbookBlock` (khối `domain_skill` thứ hai, tối đa 7.000 ký tự).
- Không có migration mới. Không đổi quyền, không đổi đường đăng bài.
- Test mới: `RedoScopeTest` (+6), `ManagerEvidenceReviewTest` (2), `ExpertTemplatesTest` (3); sửa 2 khẳng định cũ của `RedoScopeTest` và danh sách vai trò của `AgentRolesTest` vì hành vi đã chủ ý đổi.

## 3. Vai trò

Đã thêm vào `resources/agents/roles/`:

| Vai trò | Trạng thái thật | Ghi chú |
|---|---|---|
| **Người nghiên cứu** (`researcher`) | Đã nối: các bước tìm nguồn, nạp kho, soát tài liệu ghi tên vai trò | Kỹ năng `evidence-hunting` (thang nguồn, cách đặt truy vấn, xử lý 0 kết quả) và `trend-research` (mới là hướng dẫn, chưa tự chạy) |
| **Người lập kế hoạch nội dung** (`planner`) | Chưa nối: việc này chạy ở trang Kế hoạch | Kỹ năng `planner/content-calendar` |
| **Người phân tích tệp** (`file_analyst`) | Chưa nối: nhập tệp kế hoạch chạy ở trang Kế hoạch | Kỹ năng `analyst/file-intake` |
| **Kiểm chứng viên y khoa** (`fact_checker`) | Đề xuất (idea) | Hiện nằm trong bước Biên tập (kỹ năng `ymyl-medical-review` được nạp khi rủi ro vừa/cao). Chỉ tách riêng khi có chuyên gia y khoa nghiệm thu |

Giải thích "ai là người nghiên cứu xu hướng, lập kế hoạch, phân tích file": trước đây ba việc này **có code** (từ khóa/SERP/Search Console, trang Kế hoạch, nhập tệp) nhưng không có tên trong sổ vai trò nên không thấy ở tab Vai trò. Nay hai việc sau hiện tên và ghi rõ "chưa vào nhật ký phiên chạy bài".

### Đề xuất thêm (chưa làm, cần bạn quyết)
1. **Người đọc thử** (reader advocate): đọc bài như người đọc lo lắng trên điện thoại, báo chỗ khó hiểu, mục lục thừa, đoạn lặp. Dữ liệu cho thấy biên tập viên phải bắt lỗi "cấu trúc rối, lặp ý, hỏi–đáp thiếu câu hỏi" ở nhiều phiên (mục 5).
2. **Người kiểm ảnh** (image QA): đối chiếu ảnh đã sinh với yêu cầu (có đúng thông điệp, chữ có đúng dấu, có đúng quy tắc). Hiện không ai kiểm ảnh sau khi sinh; kỹ năng `designer/image-qa` có sẵn nhưng chưa nối bước.
3. **Người làm ảnh bù**: khi nhà cung cấp ảnh hết hạn ngạch (xem mục 5) tự xếp lại một lượt "chỉ ảnh" sau giờ đặt lại hạn ngạch. Nay đã có chế độ "chỉ ảnh" rẻ nên việc này khả thi.
4. **Người rà soát định kỳ**: bài y tế sắp quá hạn rà soát (khuyến cáo đổi theo năm). Kỹ năng `monitor/refresh-trigger` có sẵn.

## 4. Huấn luyện và kỹ năng

**Phát hiện quan trọng:** persona và quy tắc mặc định của từng vai trò (ở tab Huấn luyện) chỉ hiển thị; **chỉ khi quản trị tự lưu tùy chỉnh thì mới đi vào prompt** (`AgentProfiles::promptBlock` trả null khi chưa tùy chỉnh). Nên một phần "nhân viên chưa chuyên nghiệp" là do chưa ai dạy, không phải do model.

Đã làm:
- Tab Huấn luyện → Chỉ dẫn: khung **Cách dạy nhanh** (3 bước) và nút **Nạp mẫu chuyên gia** điền sẵn persona + quy tắc cho 6 vai trò (Người viết, Biên tập, Chiến lược, Họa viên, Quản lý SEO, Người nghiên cứu). Nút chỉ điền vào ô, **không tự lưu**; có hiệu lực khi bấm Lưu và hoàn tác được. Mẫu không chứa số liệu y khoa (có test).
- Kỹ năng mới, được nạp vào prompt thật: `writer/health-nutrition-writing` (nạp thêm khi chủ đề rủi ro cao/bắt buộc nguồn), `editor/ymyl-medical-review` (nạp vào lượt biên tập khi rủi ro vừa/cao). Cập nhật `manager/redo-coordination`, `designer/image-design`. Kỹ năng mới không nạp vào bước (hướng dẫn): `designer/health-infographic`, `manager/run-supervision`, `researcher/*`, `planner/*`, `analyst/file-intake`.
- **Không có chuyên gia y khoa rà các kỹ năng này.** Chúng viết theo thông lệ biên tập y khoa phổ thông và cố ý không chứa con số y khoa; con số vẫn chỉ được lấy từ nguồn duyệt.

## 5. Đánh giá hiệu quả từng vai trò từ dữ liệu thật

Nguồn: bản xem thử cục bộ `wt/ag-s27/database/preview.sqlite` (110 phiên viết bài, các phiên đến 16/11). **Đây không phải dữ liệu của server `autoseo.addp.vn`** (không có quyền truy cập); số liệu server có thể khác. Số bước theo vai trò tính từ phiên #150 trở đi.

| Vai trò | Quan sát | Nhận định |
|---|---|---|
| Kết quả chung | 79/110 phiên tới "chờ duyệt", 24 thất bại, 3 giữ nháp, 3 chặn. Chi phí trung bình phiên thành công khoảng 7.000 ₫ | 22% thất bại là cao; phần lớn là lỗi nhà cung cấp AI/hạn ngạch, chưa phân loại riêng ở đây |
| Họa viên | 23/31 bước ảnh cảnh báo; phần lớn **HTTP 429 hết hạn ngạch ảnh**, không phải do thiết kế | Vấn đề hạ tầng/hạn ngạch trước, chất lượng sau. Với ảnh tạo được: ảnh bìa là "người trung niên cầm sổ khám bên cửa sổ" (cảnh tâm trạng, ít thông tin, có hình người), 2 ảnh trong bài tốt hơn (thanh chỉ số 3 vùng màu, đĩa ăn chia phần). Prompt cuối còn đuôi dài "Friendly, calm, stylized…" lặp ở mọi ảnh |
| Biên tập | 50/166 bước cảnh báo; nội dung hay lặp: "cấu trúc rối (mục lục lẫn tóm tắt), lặp ý, hỏi–đáp thiếu câu hỏi, ngưỡng chẩn đoán mơ hồ" | Bắt lỗi đúng và nhất quán; nhưng cùng một loại lỗi cấu trúc lặp lại qua nhiều bài nghĩa là Người viết chưa học được từ phản hồi → nên dùng tab **Bài học** để ghi quy tắc; đề xuất vai trò "Người đọc thử" |
| Người viết | 25/100 bước viết cảnh báo | Cần phân tích sâu hơn theo mã luật; chưa làm trong đợt này |
| Chiến lược | 50 ok, 2 cảnh báo, 9 bỏ qua | Ổn về mặt vận hành; chất lượng dàn ý chưa đo |
| Quản lý SEO (cũ) | 9 bước, đều là đọc lời nhắn làm lại | Đúng như bạn nhận xét: gần như không có vai trò quyết định trong phiên thường |
| Nạp kho / nghiên cứu | Nhiều lượt "0 nguồn" (mục 1, điểm 4) | Do nguồn và tải trang, không do model |

## 6. Chưa làm và rủi ro

- Chưa chạy lại bất kỳ model thật nào, chưa thử trên staging/server `autoseo.addp.vn`.
- Chữ trong ảnh phụ thuộc model ảnh: chưa biết model đang gán vẽ chữ tiếng Việt có dấu đúng đến đâu. Cần thử 3–5 ảnh thật.
- `reviewEvidenceAgainstOutline` dùng so khớp từ (không AI) nên có thể báo nhầm "mục thiếu tài liệu" khi tiêu đề mục dùng từ khác với tài liệu; chỉ tốn tối đa 2 lượt tìm và có trần chi phí. Chưa đo tỉ lệ báo nhầm.
- Chỉ dẫn lưu theo bài kiểu "chỉ tạo ảnh" sẽ áp cho MỌI lần giao tiếp theo của bài đó cho tới khi xóa chỉ dẫn; nhật ký phiên ghi rõ "đã đọc chỉ dẫn của bài". Nên xóa chỉ dẫn sau khi dùng.
- Mẫu chuyên gia và kỹ năng y khoa chưa có chuyên gia y khoa rà (dự án chưa có).
- Chưa làm: nối lại tự động ảnh bù khi hết hạn ngạch; vai trò Người đọc thử / Người kiểm ảnh; phân tích sâu lỗi của Người viết; làm mới tab Huấn luyện ngoài nút mẫu và hướng dẫn nhanh.
- 5 test có sẵn trên `origin/main` vẫn lỗi trong môi trường này (AgentRunWebResearch, AgentTeamHub, AiImageRoute, Campaign, ImagesLinks); không do nhánh này (đã ghi từ trước ở bộ nhớ dự án, xác nhận cùng tên).

## 7. Cách thử sáng mai (10 phút)
1. Mở một bài đã có bản nháp → trang chi tiết bản bài → bấm **Giao làm lại** (nút mới) → gõ: `Chỉ tạo lại ảnh bìa có chữ «Tên tiêu đề ngắn». Giữ nguyên bài viết.` Kỳ vọng: nhật ký có bước "Điều phối đã đọc lời nhắn", phạm vi "chỉ làm lại ảnh, giữ nguyên chữ của bài", **không** có bước viết/chấm, bản mới v+1 chữ y hệt bản cũ, ảnh bìa mới. Xem chữ trên ảnh có đúng dấu không.
2. Vào bài khác → **Chỉ dẫn cho đội AI** → gõ `Chỉ tạo lại ảnh, giữ nguyên bài viết.` → **Giao đội AI**. Kỳ vọng như trên, bước điều phối ghi "đã đọc chỉ dẫn của bài". Xóa chỉ dẫn sau đó.
3. Đội AI → Vai trò: thấy 13 nhân sự, trong đó Người nghiên cứu, Người lập kế hoạch, Người phân tích tệp, Kiểm chứng viên y khoa (đề xuất).
4. Vai trò → mở một vai trò → Huấn luyện → **Nạp mẫu chuyên gia** → đọc → Lưu (hoặc Hoàn tác).
5. Chạy một bài y tế với tìm kiếm web thật: nhật ký có thể có bước "Quản lý SEO: N mục dàn ý chưa có tài liệu xác thực…"; các bước nạp kho ra 0 nguồn nay nói rõ lý do.
