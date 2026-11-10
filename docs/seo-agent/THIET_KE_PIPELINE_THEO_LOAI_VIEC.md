# THIẾT KẾ — PIPELINE THEO LOẠI VIỆC (thay cho một pipeline cố định)

> Phiên bản 0.1 — ngày 10/11/2026 — nhánh `feat/m09/ag-s27/dev2/article-workspace`.
> Đây là **tài liệu thiết kế, chưa có dòng mã nào được viết theo nó**. Mọi mô tả "hiện trạng" ở §1 đã được đối chiếu với mã trong nhánh này (có dẫn `file:dòng`). Mọi con số token ở §3 là **ước lượng tương đối chưa đo**. Những chỗ là suy đoán được ghi rõ "(chưa kiểm)".
> Tài liệu liên quan: `AI_AUTOMATION_DESIGN_ANALYSIS.md` §7 (bảng 9 giai đoạn), `HANDOVER_2026-11-10.md` §5 (đã xây gì), `THIET_KE_KHO_TRI_TUE_TU_DONG.md` (chi tiết kho trí tuệ — tài liệu riêng, ở đây chỉ nêu điểm ghép nối).

## Tóm tắt cho người bận

Hiện nay hệ thống trình bày **một** pipeline 9 giai đoạn cho mọi việc, nhưng chỉ có một chuỗi chạy thật là "viết một bài". Đề xuất của tài liệu này: thay bằng **8 loại việc**, mỗi loại có danh sách bước, đầu ra, cổng kiểm và ngân sách riêng. Người dùng chọn đúng việc mình cần ("audit website", "khảo sát thị trường", "viết một bài"…) và chỉ trả token cho các bước của việc đó. Các loại việc nối với nhau bằng **dữ liệu đã lưu** (bản audit, cụm từ khóa, kế hoạch), không bằng cách chạy lại từ đầu. Việc tách này làm được phần lớn mà không cần sửa lõi `AgentRunOrchestrator`; phần cần Dev1 (job nền AG-B10, review lõi) được tách thành giai đoạn sau.

---

## 1. Hiện trạng (đã kiểm trong mã)

### 1.1 Cái gì chạy thật

| Thành phần | Thực tế | Dẫn chứng |
|---|---|---|
| Chuỗi viết bài | Một chuỗi **cố định, tất định**: an toàn → phân hạng rủi ro → dàn ý → viết → chấm bằng luật → sửa tối đa N vòng → bàn giao nháp. Không có tham số nào để chọn chuỗi khác. | `app/Services/Agent/Run/AgentRunOrchestrator.php:94` (`run(Article, ?User, trigger, ?campaignId)`), thân chuỗi ở `:137-279` |
| Bước dùng LLM trong chuỗi | Chỉ 3 loại lời gọi: dàn ý (`outline`), viết (`write`), sửa (`repair`). Các bước an toàn, rủi ro, chấm điểm, quyết định, bàn giao là luật, **không tốn token**. | `AgentRunOrchestrator.php:191` (dàn ý), `:304` (viết/sửa), `:330` (chấm bằng `ContentQualityChecker`) |
| Bỏ bước | Chỉ có **một** điểm bỏ bước: tắt vai trò Chiến lược thì bỏ dàn ý. Không bỏ được bước nào khác theo loại việc. | `AgentRunOrchestrator.php:183-186` |
| Ngân sách | Một ngân sách chung cho cả phiên (mặc định 50.000 ₫), **chỉ được kiểm trước mỗi vòng sửa**, không kiểm trước dàn ý hay bản viết đầu. Có thêm trần chi phí mỗi lần gọi theo route model. | `RunPolicy.php:10`; `AgentRunOrchestrator.php:210`, `:463` |
| Chọn model theo việc | Đã có định tuyến theo 5 tác vụ với 3 bậc giá: `outline` (rẻ), `write` (suy luận), `repair` (chuẩn), `summary` (rẻ), `knowledge` (rẻ). | `app/Models/AiModelRoute.php:12-19`; `AiModelRouter.php:24` |
| Chiến dịch | `CampaignRunner::tick` kiểm hạn mức ngày (số bài, số nháp, chi phí), chọn bài kế tiếp từ Kế hoạch hoặc danh sách từ khóa, rồi gọi **đúng chuỗi viết bài ở trên**. | `CampaignRunner.php:31`, gọi orchestrator ở `:90` |
| Cách kích hoạt | Chạy **đồng bộ**: nút trên trang bài viết, lệnh `agent:run`, và lệnh `agent:tick` theo lịch 15 phút. Chưa có job hàng đợi cho đội AI. | `ArticleResource.php:103`; `routes/console.php:26`; `app/Jobs/` chỉ có 2 job xuất bản |
| Các việc ngoài viết bài | Đã có dịch vụ riêng nhưng **không ghi vào `agent_runs`** và không xuất hiện như một "phiên chạy": quét audit (`runFullAudit`), gom cụm từ khóa (`KeywordService::propose`), đối thủ (`CompetitorService`), tách kiến thức bằng AI (`KnowledgeAiIngest`, có trần chi phí riêng). | `app/Services/Crawler/SeoAuditService.php:92`; `app/Filament/Support/Keywords/KeywordService.php:139`; `app/Services/Knowledge/KnowledgeAiIngest.php` |
| Làm mới bài cũ | Đã có ở dạng **một nhánh trong bước viết**: bài `kind=refresh, origin=audit` được truyền thêm `options['refresh']`. Vẫn chạy nguyên chuỗi viết bài mới (kể cả dàn ý). | `app/Services/Audit/RefreshOptions.php:14`; `AgentRunOrchestrator.php:302` |

### 1.2 Cái gì chỉ hiển thị

| Thành phần | Thực tế | Dẫn chứng |
|---|---|---|
| Bản đồ 9 giai đoạn | `PipelineBlueprint::definitions()` khai báo 9 giai đoạn: `site, audit, keywords, competitors, plan, write, quality, publish, measure`. **Sáu giai đoạn có `run_stages` rỗng** (site, audit, keywords, competitors, plan, measure), tức không có bước chạy nào của đội AI gắn với chúng. Chỉ `write`, `quality`, `publish` (bàn giao nháp) đọc số liệu từ `agent_run_steps`. | `app/Services/Agent/Pipeline/PipelineBlueprint.php:28-45`; `:61` (`wired`) |
| Nút điều khiển pipeline | `controlsWired()` trả `false`, nên nút "Chạy lại"/"Tạm dừng pipeline" không hiện. Lý do ghi trong mã: chưa có job nền và API pipeline (AG-B10). | `PipelineBlueprint.php:19-22` |
| Mức sẵn sàng | Sổ tính năng tự ghi: Audit, Từ khóa, Kế hoạch, Đối thủ ở mức `demo`; Search Console `not_connected`; "Pipeline tự động có điều phối" ở mức `idea`. | `app/Filament/Support/Agent/Features/core.php:29-39`; `Features/automation.php:13` |
| Trang chi tiết phiên chạy | Vẽ luồng theo danh sách chặng **viết cứng**: `guard, risk, brief, write, grade, repair, handoff`. | `app/Filament/Support/Agent/RunFlow.php:18`; `AgentFlowBlueprint.php:16-52` |

### 1.3 Ràng buộc dữ liệu quan trọng

Bảng `agent_runs` hiện **bắt buộc có bài viết**: cột `article_id` là khóa ngoại không cho rỗng (`database/migrations/2026_11_07_000001_create_agent_runs_tables.php:18`). Cột `agent_run_steps.stage` dài tối đa 20 ký tự (`:43`). Hai điều này quyết định phương án dữ liệu ở §4.2: một phiên "audit website" không có bài viết nào thì hiện không lưu được vào `agent_runs`.

### 1.4 Mức bằng chứng

Theo `HANDOVER_2026-11-10.md` §5, toàn bộ phần đội AI "mới ở mức 1–2" (kiểm bằng test giả lập và chạy thử cục bộ). `PipelineBlueprint.php:39` cũng tự ghi chuỗi viết "chưa qua model thật ở quy mô". Tài liệu này **không** có số đo token thật nào; việc đo là một hạng mục của giai đoạn G0 (§6).

---

## 2. Vấn đề

### 2.1 Vì sao tốn token

1. **Chuỗi viết bài là đơn vị nhỏ nhất.** Muốn có bất cứ kết quả AI nào trong khung "pipeline", người dùng phải chạy cả chuỗi dàn ý + viết (model bậc suy luận, đắt nhất) + tối đa 3 vòng sửa. Không có cách chạy "chỉ lập dàn ý để tôi xem" hay "chỉ chấm lại bản tôi vừa sửa tay" (chấm là luật, lẽ ra tốn 0 token).
2. **Làm mới bài cũ trả giá như viết bài mới.** Bài `refresh` vẫn lập dàn ý mới và viết lại toàn bộ, dù việc cần làm có thể chỉ là sửa tiêu đề, mô tả meta hoặc bổ sung một mục.
3. **Ngữ cảnh được dựng lại mỗi phiên.** Mỗi lần chạy đều nạp lại hồ sơ thương hiệu, kiến thức, ảnh, liên kết nội bộ và nhét vào lời nhắc của cả bước dàn ý lẫn bước viết (`AgentRunOrchestrator.php:190`, `:291-301`). Phần ngữ cảnh ổn định theo website (hồ sơ thương hiệu, hồ sơ lĩnh vực) không được tóm tắt một lần rồi dùng lại. *(Mức tốn thực tế chưa đo; nhà cung cấp có hỗ trợ cache lời nhắc hay không tùy từng provider — chưa kiểm.)*
4. **Ngân sách không gắn với từng bước.** Chỉ có một con số cho cả phiên và chỉ kiểm trước vòng sửa. Một bản viết đầu rất dài vẫn được gọi dù đã gần hết ngân sách.
5. **Khi các giai đoạn 1–5 và 9 được nối thật, cách làm "một chuỗi cho mọi việc" sẽ nhân chi phí lên.** Nếu mỗi bài đều kéo theo audit, khảo sát từ khóa, phân tích đối thủ thì chi phí cho 20 bài là 20 lần khảo sát, trong khi kết quả khảo sát của một website gần như không đổi trong vài tuần. Đây là rủi ro của thiết kế hiện tại, chưa phải chi phí đang phát sinh (các giai đoạn đó chưa chạy).

### 2.2 Vì sao khó hiểu với người dùng

1. **Bản đồ nói 9, máy chạy 3.** Người làm SEO nhìn 9 ô sẽ hiểu rằng bấm chạy là hệ thống đi qua cả 9. Thực tế 6 ô chỉ là nhãn trạng thái tính năng.
2. **Không trả lời được câu "tôi muốn làm việc X thì bấm ở đâu, nhận về cái gì, tốn bao nhiêu".** Audit ở một trang, từ khóa ở trang khác, viết bài ở trang khác; không nơi nào nói chúng nối với nhau thế nào.
3. **Phiên chạy luôn mang tên một bài viết.** Một lần quét audit hay một lần gom cụm từ khóa không để lại "phiên" nào để xem lại lý do, chi phí, kết quả, trong khi các việc đó cũng do đội AI làm.
4. **Không có đầu ra trung gian có tên.** Người dùng không thấy "Bản khảo sát thị trường ngày 10/11" như một sản phẩm có thể mở, duyệt, dùng lại.

---

## 3. Đề xuất: khái niệm "loại việc"

**Loại việc (job type)** là một việc người dùng có thể gọi tên và yêu cầu, có đầu vào rõ, **một đầu ra chính có tên**, và danh sách bước riêng. Một lần thực hiện một loại việc là một **phiên chạy**. Pipeline 9 giai đoạn không mất đi: nó trở thành **bản đồ hành trình** cho thấy các loại việc nối nhau ra sao (§4.4), còn thứ thực sự chạy là từng loại việc.

Ký hiệu trong các bảng dưới: **[L]** = bước luật/đọc dữ liệu, không tốn token; **[AI-rẻ]**, **[AI-chuẩn]**, **[AI-suy luận]** = bước gọi LLM theo bậc giá của `AiModelRoute::TIERS`. Cột "Token tương đối" lấy **một bài viết đơn lẻ không sửa = 1,0 đơn vị** làm mốc; đây là ước lượng theo số lời gọi và độ dài dự kiến, **chưa đo**.

### 3.1 Bảng tổng quan 8 loại việc

| Mã | Tên hiển thị | Mục đích | Đầu ra chính | Token tương đối (ước lượng) | Hiện trạng nền |
|---|---|---|---|---|---|
| `site_audit` | Audit website | Biết website đang có lỗi kỹ thuật/nội dung gì, ưu tiên sửa gì | **Báo cáo audit** (nhóm lỗi + tóm tắt + đề xuất làm mới) | 0 – 0,1 | Quét và nhóm lỗi đã có, tóm tắt AI chưa có |
| `market_research` | Khảo sát thị trường | Biết nên viết về từ khóa nào, đối thủ đang hơn ở đâu | **Bản khảo sát** (cụm từ khóa + khoảng trống so với đối thủ) | 0,1 – 0,3 | Từ khóa nhập tay/CSV và gom cụm đã có; nguồn dữ liệu thật chưa có |
| `content_plan` | Lập kế hoạch nội dung | Biến khảo sát thành danh sách bài có thứ tự ưu tiên | **Kế hoạch** (các bài `planned` kèm brief ngắn) | 0,1 – 0,2 | Trang Kế hoạch do người xếp; bước AI chưa có |
| `article_single` | Viết bài đơn lẻ | Có một bản nháp đạt luật cho một từ khóa | **Bản nháp chờ duyệt** | 1,0 – 2,5 (tùy số vòng sửa) | **Đang chạy** (chuỗi hiện tại) |
| `article_campaign` | Viết theo chiến dịch/chủ đề | Sản xuất đều đặn nhiều bài cùng chủ đề trong hạn mức | **Loạt bản nháp** + báo cáo chiến dịch | N × (0,8 – 2,3) | **Đang chạy** (`CampaignRunner`), chưa dùng chung brief |
| `article_refresh` | Làm mới bài cũ | Sửa đúng chỗ bài đang có vấn đề, không viết lại cả bài | **Bản sửa đổi** (phiên bản mới + danh sách thay đổi) | 0,2 – 0,8 | Đang chạy nhờ chuỗi viết mới (đắt hơn cần thiết) |
| `brain_build` | Xây/cập nhật kho trí tuệ | Nạp tài liệu thành mục kiến thức chờ duyệt | **Mục kiến thức đề xuất** | Theo số tài liệu (có trần riêng) | Tách bằng AI đã có (`KnowledgeAiIngest`) |
| `measure` | Đo lường | Biết bài nào hiệu quả, bài nào cần làm mới | **Báo cáo hiệu quả** + đề xuất làm mới | 0 – 0,1 | Chưa có (Search Console chưa nối) |

### 3.2 Chi tiết từng loại việc

#### A. `site_audit` — Audit website

| Mục | Nội dung |
|---|---|
| Đầu vào | Website đã kết nối; tùy chọn: giới hạn số URL, sitemap. |
| Bước | 1. Kiểm an toàn (dừng khẩn cấp, website hoạt động) **[L]** → 2. Quét sitemap và từng URL **[L]** (đã có: `SeoAuditService::runFullAudit`) → 3. Nhóm lỗi theo danh mục **[L]** → 4. Chấm điểm và so với lần trước **[L]** → 5. Tóm tắt "3 việc nên làm trước" bằng lời **[AI-rẻ]**, *bỏ qua được* → 6. Sinh đề xuất làm mới bài **[L]** (đã có: `AuditSuggestionService`). |
| Đầu ra | Bản ghi `seo_audits` + danh sách nhóm lỗi + (nếu có) đoạn tóm tắt + các `audit_suggestions`. |
| Cổng kiểm | Quét xong ít nhất X% URL; nếu không đạt thì báo cáo ghi rõ "quét một phần". |
| Người can thiệp | Chọn nhóm lỗi nào thành việc làm mới. Không cần duyệt báo cáo. |
| Điều kiện bỏ bước 5 | Không có thay đổi so với lần quét trước; hoặc chưa cấu hình route `summary`; hoặc người dùng chọn "không tóm tắt". |
| Token | Gần 0 nếu bỏ bước 5; một lời gọi rẻ nếu có. |
| Chưa có | Điểm audit tính từ crawler thật và chạy nền (ghi tại `PipelineBlueprint.php:31`). |

#### B. `market_research` — Khảo sát thị trường (từ khóa + đối thủ)

| Mục | Nội dung |
|---|---|
| Đầu vào | Website; danh sách từ khóa hạt giống (nhập tay/CSV/nguồn dữ liệu nếu có); danh sách tên miền đối thủ (tùy chọn). |
| Bước | 1. Kiểm an toàn **[L]** → 2. Gom từ khóa từ các nguồn, loại trùng **[L]** → 3. Gom cụm theo luật **[L]** (đã có: `KeywordService::propose`) → 4. Đặt tên cụm và gán ý định tìm kiếm cho cụm chưa rõ **[AI-rẻ]**, *chỉ chạy với cụm mới hoặc đổi* → 5. Lấy dữ liệu đối thủ từ nguồn đã nối **[L]**, *bỏ qua nếu chưa có nguồn* → 6. Tính khoảng trống từ khóa **[L]** → 7. Viết nhận định ngắn cho mỗi khoảng trống lớn **[AI-rẻ]**, *bỏ qua được*. |
| Đầu ra | **Bản khảo sát** có ngày: cụm từ khóa (đề xuất, chờ chấp nhận) + bảng khoảng trống + ghi chú giới hạn ("không có dữ liệu đối thủ"). |
| Cổng kiểm | Có ít nhất một cụm hợp lệ; mọi tên miền đối thủ nằm trong danh sách cho phép (chống SSRF — việc Dev1 theo `PipelineBlueprint.php:35`). |
| Người can thiệp | Chấp nhận/gộp/tách cụm (màn Từ khóa đã có các thao tác này). |
| Token | Thấp: lời gọi rẻ, gom nhiều cụm vào một lời gọi. |
| Chưa có | Nguồn dữ liệu từ khóa thật và crawl đối thủ thật. Khi chưa có, loại việc này chạy ở dạng giảm cấp trên dữ liệu nhập tay và **phải ghi rõ** điều đó trong bản khảo sát. |

#### C. `content_plan` — Lập kế hoạch nội dung

| Mục | Nội dung |
|---|---|
| Đầu vào | Bản khảo sát đã chấp nhận (cụm từ khóa); các bài đã có trên website; hồ sơ doanh nghiệp; số bài mỗi tuần mong muốn. |
| Bước | 1. Kiểm an toàn **[L]** → 2. Loại từ khóa đã có bài **[L]** (cùng luật với `CampaignRunner::nextKeyword`) → 3. Kiểm phù hợp website **[L]** (đã có: `BrandProfileData::looksOffTopic`) → 4. Xếp ưu tiên theo điểm luật (khối lượng, khoảng trống, hạng rủi ro) **[L]** → 5. Viết brief ngắn cho mỗi bài được chọn (góc tiếp cận, ý chính) **[AI-rẻ]**, *gom theo lô* → 6. Tạo các bài `planned` **[L]**. |
| Đầu ra | **Kế hoạch**: danh sách bài `planned` có thứ tự, mỗi bài kèm brief ngắn. |
| Cổng kiểm | Không bài nào trùng từ khóa với bài đã có; bài lệch chủ đề bị đánh dấu chứ không tự loại. |
| Người can thiệp | **Duyệt kế hoạch** trước khi bài vào hàng chờ viết (đây là điểm can thiệp rẻ nhất và có giá trị nhất: sửa kế hoạch tốn 0 token, sửa bài đã viết thì tốn). |
| Token | Thấp. |
| Ghi chú | Brief sinh ở đây được **dùng lại** cho bước dàn ý của loại D/E (§4.3), tránh lập dàn ý hai lần. |

#### D. `article_single` — Viết bài đơn lẻ

| Mục | Nội dung |
|---|---|
| Đầu vào | Một bài (từ khóa chính); tùy chọn: brief/dàn ý có sẵn, Trí tuệ áp dụng. |
| Bước | 1. Kiểm an toàn **[L]** → 2. Phân hạng rủi ro **[L]** → 3. Dàn ý **[AI-rẻ]**, *bỏ qua nếu bài đã có brief/dàn ý được duyệt hoặc vai trò Chiến lược tắt* → 4. Viết bản đầu **[AI-suy luận]** → 5. Chấm bằng luật **[L]** → 6. Sửa theo lỗi chặn **[AI-chuẩn]**, *0–3 vòng, chỉ khi có lỗi P0* → 7. Quyết định và bàn giao nháp **[L]**. |
| Đầu ra | **Bản nháp** (`ArticleVersion`) ở trạng thái chờ duyệt hoặc giữ nháp, kèm điểm và lý do. |
| Cổng kiểm | Không còn lỗi chặn P0; bài rủi ro cao hoặc lĩnh vực bắt buộc nguồn thì giữ cho người có chuyên môn. |
| Người can thiệp | Duyệt bản nháp (luôn có ở bậc T1). |
| Token | 1,0 khi đạt ngay; tới khoảng 2,5 khi sửa đủ 3 vòng. |
| Ghi chú | Đây **chính là chuỗi hiện tại**, giữ nguyên hành vi. Thay đổi duy nhất được đề xuất: nhận dàn ý có sẵn để bỏ bước 3, và kiểm ngân sách trước bước 4. |

#### E. `article_campaign` — Viết theo chiến dịch/chủ đề

| Mục | Nội dung |
|---|---|
| Đầu vào | Chiến dịch (website, nguồn bài: Kế hoạch hoặc danh sách từ khóa, hạn mức ngày, chế độ, Trí tuệ). |
| Bước (một lần cho chiến dịch) | 0. Lập **brief chủ đề** dùng chung: giọng văn, ý chính, các bài trong chủ đề liên kết nhau thế nào **[AI-rẻ]**, *chạy một lần, lưu lại, chỉ chạy lại khi chiến dịch đổi cấu hình*. |
| Bước (mỗi nhịp) | 1. Kiểm hạn mức ngày: số bài, số nháp, chi phí **[L]** (đã có) → 2. Chọn bài kế tiếp **[L]** (đã có) → 3. Chạy loại D cho bài đó, truyền brief chủ đề và brief bài (nếu có từ loại C) để **bỏ bước dàn ý**. |
| Đầu ra | Loạt bản nháp + **báo cáo chiến dịch** (đã giao bao nhiêu, đạt bao nhiêu, chi phí, mục đang chờ bổ sung). |
| Cổng kiểm | Các trần ngày hiện có; chế độ Tự chủ vẫn khóa (`CampaignRunner.php:53`). |
| Người can thiệp | Duyệt từng bản nháp; bổ sung khi có mục "chờ bạn bổ sung" (đã có, hạn 24 giờ). |
| Token | Mỗi bài rẻ hơn loại D một chút nhờ bỏ dàn ý riêng, cộng một lời gọi rẻ cho brief chủ đề. Mức tiết kiệm thực chưa đo. |

#### F. `article_refresh` — Làm mới bài cũ

| Mục | Nội dung |
|---|---|
| Đầu vào | Một bài đã có phiên bản; lý do làm mới (mã lỗi audit, hoặc tín hiệu đo lường, hoặc người dùng yêu cầu). |
| Bước | 1. Kiểm an toàn **[L]** → 2. Chấm bản hiện tại bằng luật **[L]** → 3. **Phân loại phạm vi sửa** theo mã lỗi **[L]**: (a) chỉ siêu dữ liệu (tiêu đề SEO, mô tả meta), (b) sửa một số mục, (c) viết lại → 4a. Sinh lại siêu dữ liệu **[AI-rẻ]**; hoặc 4b. Sửa các mục có lỗi **[AI-chuẩn]**, chỉ gửi mục cần sửa; hoặc 4c. chuyển sang chuỗi loại D → 5. Chấm lại **[L]** → 6. Bàn giao **bản sửa đổi** kèm danh sách thay đổi **[L]**. |
| Đầu ra | Phiên bản mới của bài + bảng "đã đổi gì, vì lỗi nào". |
| Cổng kiểm | Điểm luật sau sửa không thấp hơn trước; không còn lỗi P0. |
| Người can thiệp | Duyệt bản sửa đổi (xem dạng so sánh trước/sau). |
| Token | Từ khoảng 0,2 (chỉ siêu dữ liệu) đến 0,8 (sửa nhiều mục). Chỉ nhánh (c) mới bằng loại D. |
| Chưa có | Nhánh 4a và 4b cần phương thức AI mới (sửa một phần). Phương thức này nằm ở tầng nhà cung cấp AI, thuộc lõi cần Dev1 review (§6, G4). Trước khi có, loại F chạy nhánh (c) như hiện nay. |

#### G. `brain_build` — Xây/cập nhật kho trí tuệ (chỉ nêu điểm ghép nối)

Chi tiết các bước thuộc `THIET_KE_KHO_TRI_TUE_TU_DONG.md`. Tài liệu này chỉ quy định **hợp đồng ghép nối** để loại việc này hiện diện cùng chỗ với các loại khác:

| Mục | Nội dung |
|---|---|
| Đăng ký | Có một mục trong registry với mã `brain_build`, tên, mô tả, danh sách bước để hiển thị. |
| Phiên chạy | Mỗi lần nạp ghi một phiên chạy và các bước (model, token, chi phí) theo cùng định dạng, để trang Phiên chạy và trang Chi phí AI cộng được. |
| Ngân sách | Tuân theo trần của loại việc và trần ngày của website (§5.3). `KnowledgeAiIngest` đã có trần riêng theo nguồn; hai trần cùng áp dụng, cái nào chạm trước thì dừng. |
| Đầu ra cho loại khác | Mục kiến thức **đã duyệt** được loại D/E/F đọc qua `KnowledgeContext` như hiện nay. Mục chưa duyệt không bao giờ vào bài. |
| Đầu vào từ loại khác | "Khoảng trống kiến thức" phát hiện khi viết bài (đã có `KnowledgeGaps`) là gợi ý để chạy `brain_build`. |
| Người can thiệp | Duyệt mục kiến thức (bắt buộc, đã có). |

#### H. `measure` — Đo lường

| Mục | Nội dung |
|---|---|
| Đầu vào | Website đã nối Search Console; khoảng thời gian. |
| Bước | 1. Kiểm an toàn và kết nối **[L]** → 2. Kéo số liệu **[L]** → 3. Ghép số liệu với bài đã đăng **[L]** → 4. Phát hiện bài tụt hạng/CTR thấp theo ngưỡng **[L]** → 5. Tóm tắt và nêu lý do có thể **[AI-rẻ]**, *bỏ qua được* → 6. Tạo đề xuất làm mới **[L]**. |
| Đầu ra | **Báo cáo hiệu quả** + đề xuất làm mới (đầu vào cho loại F). |
| Cổng kiểm | Có dữ liệu trong khoảng thời gian; nếu không thì dừng ở bước 1 và ghi "Chưa có dữ liệu", không tốn token. |
| Người can thiệp | Chọn đề xuất nào thành việc. |
| Token | Gần 0. |
| Chưa có | Toàn bộ, vì Search Console chưa kết nối (`PipelineBlueprint.php:45`). Loại việc này được **khai báo ở trạng thái "Chưa kết nối"** và không chạy được cho tới khi Task 5.1 xong. |

### 3.3 Điều rút ra từ các bảng

Trong 8 loại việc, chỉ loại D, E và nhánh (c) của F cần model đắt. Năm loại còn lại chủ yếu là luật cộng một vài lời gọi rẻ có thể bỏ qua. Vì vậy tách theo loại việc không chỉ giúp dễ hiểu: nó khiến **phần lớn thao tác hằng ngày của người làm SEO gần như không tốn token**, và dồn chi phí vào đúng chỗ tạo ra bài viết.

---

## 4. Kiến trúc

### 4.1 Registry định nghĩa pipeline

Một lớp mới `App\Services\Agent\Pipeline\JobTypeRegistry` là **nguồn duy nhất** trả lời "có những loại việc nào, mỗi loại gồm bước gì". Theo cách `FeatureRegistry` đang làm (`app/Filament/Support/Agent/FeatureRegistry.php:19`), mỗi loại việc là **một file** trong `resources/agents/job-types/<mã>.php` trả về một mảng, để nhiều người thêm loại việc mà không sửa chung một file. *(Thư mục `resources/agents/` đã tồn tại cho `roles/`; việc thêm thư mục con cần Dev1 xác nhận theo Q10 của tài liệu phân tích.)*

Hình dạng khai báo (minh họa, tên trường có thể đổi khi làm):

```php
// resources/agents/job-types/article_refresh.php — MINH HỌA, chưa tồn tại
return [
    'id' => 'article_refresh',
    'title' => 'Làm mới bài cũ',
    'purpose' => 'Sửa đúng chỗ bài đang có vấn đề, không viết lại cả bài.',
    'subject' => 'article',                  // phiên gắn với: website | article | campaign | brain
    'output' => 'Bản sửa đổi kèm danh sách thay đổi',
    'features' => ['content.generate', 'content.quality'], // nối FeatureRegistry để tính mức sẵn sàng
    'budget' => ['tokens' => 12000, 'vnd' => 8000],        // trần của cả phiên (số minh họa)
    'steps' => [
        ['id' => 'guard',    'kind' => 'rule', 'title' => 'An toàn'],
        ['id' => 'grade',    'kind' => 'rule', 'title' => 'Chấm bản hiện tại'],
        ['id' => 'scope',    'kind' => 'rule', 'title' => 'Phân loại phạm vi sửa'],
        ['id' => 'meta',     'kind' => 'llm',  'task' => 'summary', 'title' => 'Sinh lại tiêu đề và mô tả',
            'when' => 'scope_is_meta', 'budget' => ['tokens' => 1500]],
        ['id' => 'patch',    'kind' => 'llm',  'task' => 'repair',  'title' => 'Sửa các mục có lỗi',
            'when' => 'scope_is_sections', 'budget' => ['tokens' => 8000]],
        ['id' => 'regrade',  'kind' => 'rule', 'title' => 'Chấm lại'],
        ['id' => 'handoff',  'kind' => 'rule', 'title' => 'Bàn giao bản sửa đổi', 'gate' => 'Không còn lỗi P0, điểm không giảm'],
    ],
    'human' => ['after' => 'handoff', 'what' => 'Duyệt bản sửa đổi'],
    'runner' => \App\Services\Agent\Pipeline\Runners\ArticleRefreshRunner::class,
];
```

Các quy tắc của registry:

| Quy tắc | Lý do |
|---|---|
| Mỗi bước có `kind`: `rule` hoặc `llm`. Bước `llm` **bắt buộc** khai báo `task` thuộc `AiModelRoute::TASKS`. | Để giao diện hiển thị trước bước nào tốn token và dùng bậc model nào; để định tuyến model tái dùng `AiModelRouter`. |
| Điều kiện bỏ bước (`when`) là **tên một điều kiện đã đăng ký bằng mã PHP**, không phải biểu thức tự do trong file cấu hình. | Giữ nguyên tắc "bộ điều phối tất định, không để LLM hay cấu hình tùy ý chọn bước" (`AgentRunOrchestrator.php:44`); điều kiện có test. |
| Mỗi bước bị bỏ vẫn ghi một dòng `skipped` kèm lý do. | Giữ thói quen đã có (`AgentRunOrchestrator.php:186`): người dùng thấy vì sao không tốn tiền ở bước đó. |
| Ngân sách khai ở hai mức: mỗi bước và cả loại việc. Giá trị trong file là **mặc định**; cài đặt của website/chiến dịch có thể hạ xuống, không tự nâng lên. | An toàn chi phí theo mặc định. |
| Registry **chỉ khai báo**; việc thực thi do một lớp `runner` cho từng loại việc đảm nhiệm. Không viết một "máy chạy bước" tổng quát ở giai đoạn đầu. | Xem §4.5: tránh viết lại lõi đã có test. Máy chạy tổng quát chỉ cân nhắc sau khi có 3–4 runner thật để thấy phần chung. |
| Có test kiểm tính nhất quán: mọi `task` tồn tại, mọi `when` đã đăng ký, mọi `features` có trong `FeatureRegistry`, mã bước không quá 20 ký tự. | Cùng tinh thần luật cứng của `FeatureRegistry`. |

### 4.2 Lưu phiên chạy: tái dùng `agent_runs` hay bảng mới

Vấn đề gốc: `agent_runs.article_id` không cho rỗng (§1.3), nên các loại A, B, C, G, H (không gắn một bài) hiện không lưu được.

| Phương án | Nội dung | Ưu | Nhược |
|---|---|---|---|
| **P1 — Mở rộng `agent_runs`** *(khuyến nghị)* | Thêm cột `job_type` (chuỗi, mặc định `article_single`, có chỉ mục); cho `article_id` rỗng; thêm `subject_type`/`subject_id` (chủ thể đa hình: website, chiến dịch, trí tuệ), `parent_run_id` (phiên cha, ví dụ nhịp chiến dịch → phiên viết bài), `input_refs` và `output_ref` (JSON trỏ tới bản ghi đầu vào/đầu ra), `tokens_budget`. | Trang Phiên chạy, trang Chi phí AI, `TrustMetrics`, hạn mức ngày của chiến dịch **dùng tiếp không đổi**. Dữ liệu cũ tự thành `article_single`. Một chỗ duy nhất cho câu hỏi "đội AI đã làm gì, tốn bao nhiêu". | Phải đổi khóa ngoại `article_id` thành cho rỗng. Mọi chỗ đang giả định `$run->article` luôn có phải được rà (ước chừng: `AgentRunResource`, `ViewAgentRun`, `RunFlow`, `RunActivity`, `AgentOverview` — danh sách đầy đủ **chưa rà hết**). |
| P2 — Bảng mới `pipeline_runs` + `pipeline_run_steps` | Giữ nguyên `agent_runs` cho viết bài; loại việc mới ghi vào bảng mới. | Không đụng bảng đang chạy; không rủi ro cho mã hiện có. | Hai nơi lưu phiên: trang Phiên chạy, thống kê chi phí, hạn mức phải cộng từ hai bảng. Về lâu dài phải gộp. |
| P3 — Bảng cha `pipeline_runs` bọc ngoài, `agent_runs` là con | Bảng mới giữ loại việc và đầu vào/đầu ra; mỗi phiên viết bài vẫn là một dòng `agent_runs` trỏ lên cha. | Tách sạch khái niệm; `agent_runs` gần như không đổi (thêm một cột trỏ cha). | Thêm một tầng; bước của loại không viết bài vẫn cần chỗ lưu (lại phải có bảng bước thứ hai hoặc cho `agent_run_steps` trỏ hai kiểu cha). |

**Khuyến nghị: P1.** Lý do chính là chi phí và hạn mức đã được tính trên `agent_runs` ở nhiều nơi, và chú thích của migration gốc đã ghi rõ đây là "bảng nền… Dev1 có thể đổi tên/cột, giao diện đọc qua model nên chỉ sửa một chỗ" (`2026_11_07_000001_create_agent_runs_tables.php:8-9`). Cột `agent_run_steps` không cần đổi cấu trúc: `stage` đủ chứa mã bước mới (≤ 20 ký tự), `data` (JSON) đủ chứa lý do bỏ bước và ngân sách bước.

Lưu ý trung thực: P1 là thay đổi trên bảng lõi, **bắt buộc qua Dev1** và đi PR migration riêng (§6, G1). Nếu Dev1 không đồng ý đổi `article_id`, phương án lùi là P3.

### 4.3 Các loại việc nối đầu ra – đầu vào

Nguyên tắc: **nối bằng bản ghi đã lưu, không nối bằng lời gọi lồng nhau.** Mỗi loại việc kết thúc bằng một đầu ra có tên nằm trong cơ sở dữ liệu; loại việc sau đọc bản ghi đó. Nhờ vậy một bản khảo sát làm một lần dùng cho nhiều bài, và người dùng có thể dừng giữa hai loại việc để xem, sửa.

```
 site_audit ──► audit_suggestions ─────────────────────────────┐
                                                               ▼
 market_research ──► cụm từ khóa ──► content_plan ──► bài `planned` ──► article_single ──► bản nháp
        ▲            (đã chấp nhận)        (kèm brief)          │        ▲
        │                                                       │        │ brief chủ đề
        │                                             article_campaign ──┘
        │
 measure ──► đề xuất làm mới ──► article_refresh ──► bản sửa đổi
                                        ▲
 brain_build ──► mục kiến thức đã duyệt ┴──► (được D, E, F đọc khi viết)
```

| Từ | Đầu ra (bản ghi) | Tới | Dùng để | Bảng hiện có |
|---|---|---|---|---|
| A `site_audit` | Đề xuất làm mới | F | Lý do và mã lỗi cần sửa | `audit_suggestions` (đã có, đã nối tới bước viết qua `RefreshOptions`) |
| B `market_research` | Cụm từ khóa đã chấp nhận, khoảng trống | C | Nguồn chủ đề | `keywords`, `keyword_clusters`, `keyword_gaps` (đã có) |
| C `content_plan` | Bài `planned` + brief | D, E | Bỏ bước dàn ý | `articles` (đã có); **chỗ lưu brief chưa có** — cần quyết định ở G3 |
| E `article_campaign` | Brief chủ đề | D (các bài con) | Giọng và ý chung | **chưa có chỗ lưu** — đề xuất một cột JSON trên `campaigns` |
| G `brain_build` | Mục kiến thức đã duyệt | D, E, F | Dữ kiện đưa vào bài | `knowledge_items` (đã có, đã nối) |
| H `measure` | Đề xuất làm mới | F | Lý do làm mới theo hiệu quả | chưa có |
| D, E, F | Khoảng trống kiến thức | G | Gợi ý tài liệu cần nạp | `knowledge_gaps` (đã có) |

Mỗi phiên ghi `input_refs` (ví dụ `{"audit_id": 12}`) và `output_ref`. Trang phiên chạy dựa vào đó để hiện "Phiên này dùng bản khảo sát ngày 05/11" và "Kết quả của phiên này đã được 6 bài dùng".

**Độ tươi của đầu vào.** Mỗi loại đầu ra có hạn dùng mặc định (đề xuất: audit 14 ngày, khảo sát 30 ngày — con số cần bạn chốt, §7). Khi loại việc sau thấy đầu vào quá hạn, nó **không tự chạy lại** loại trước mà báo "Bản khảo sát đã 45 ngày, bạn muốn dùng tiếp hay khảo sát lại?" (ở mức tự chủ cao hơn thì tự chạy lại nếu còn ngân sách, §5).

### 4.4 `PipelineBlueprint` và các màn hình thay đổi thế nào

| Thành phần | Hiện nay | Sau thay đổi |
|---|---|---|
| `PipelineBlueprint::definitions()` | 9 giai đoạn, mỗi giai đoạn tự khai `run_stages` | Giữ 9 giai đoạn làm **bản đồ hành trình**, thêm trường `job_types` cho biết giai đoạn do loại việc nào phục vụ (ví dụ `audit` ← `site_audit`; `keywords` và `competitors` ← `market_research`; `write`, `quality`, `publish` ← `article_single`/`article_campaign`/`article_refresh`). Số liệu giai đoạn tính từ các phiên có `job_type` tương ứng. |
| Luật "active phải có bằng chứng" | Tính từ `FeatureRegistry` + bước chạy AI thật 30 ngày (`PipelineBlueprint.php:89-105`) | **Giữ nguyên luật**, áp cho từng loại việc: một loại việc chỉ "Đang hoạt động" khi mọi tính năng liên quan `active` có bằng chứng và đã có phiên thật. Loại `measure` sẽ hiện "Chưa kết nối" một cách tự động. |
| `controlsWired()` | `false` cho cả pipeline | Chuyển thành **theo loại việc**: loại nào đã có đường chạy (đồng bộ hoặc job nền) thì hiện nút. Nút "Tạm dừng" cho cả hệ thống vẫn chờ AG-B10. |
| Tab "Pipeline" (`PipelineMap`) | Lưới 9 ô | Hai lớp: phía trên là **các thẻ loại việc** (chọn việc để làm — wireframe §8.1); phía dưới là bản đồ 9 giai đoạn thu gọn để thấy toàn cảnh. |
| Trang chi tiết phiên (`ViewAgentRun`, `RunFlow`) | Danh sách chặng viết cứng (`RunFlow.php:18`) | Danh sách bước **lấy từ registry theo `job_type` của phiên**. Phiên cũ không có `job_type` dùng `article_single` nên hiển thị như trước. |
| `AgentFlowBlueprint` | Mô tả 9 chặng của chuỗi viết | Giữ, coi là phần mô tả chi tiết của loại `article_single`; về sau gộp vào file khai báo loại việc. |
| Trang "Cách hoạt động" | Liệt kê theo tính năng | Thêm một mục liệt kê 8 loại việc với mức sẵn sàng thật. |

### 4.5 Quan hệ với `AgentRunOrchestrator` (lõi cần Dev1 review)

Đề xuất **không viết lại** orchestrator. Trình tự:

1. **Giai đoạn đầu:** `AgentRunOrchestrator` được coi là runner của loại `article_single`, giữ nguyên chữ ký `run()`. Một lớp mỏng mới (`JobDispatcher`) nhận `(job_type, chủ thể, tùy chọn)` rồi gọi runner tương ứng. Với `article_single`, nó gọi đúng orchestrator hiện tại. Thay đổi trong orchestrator ở giai đoạn này: **không có**; cột `job_type` nhận giá trị mặc định ở tầng cơ sở dữ liệu.
2. **Các loại không viết bài** (A, B, C, H) có runner riêng, **không đi qua orchestrator**. Chúng dùng `StructuredAiInterface` (đã có, `app/Contracts/StructuredAiInterface.php`) cho các lời gọi rẻ trả JSON, theo đúng mẫu an toàn của `KnowledgeAiIngest` (nội dung ngoài là dữ liệu, kiểm schema đầu ra, có trần).
3. **Phần dùng chung cần tách ra** để runner mới không chép lại: ghi bước (`step()`), thử lại có backoff (`withRetry()`), gọi AI qua router kèm dự phòng và trần chi phí (`callAi()`), kết thúc phiên (`finish()`). Việc tách này là **sửa lõi**, phải do Dev1 review hoặc tự làm. Cho tới khi tách, runner mới dùng một bản ghi bước tối giản riêng và **chấp nhận chưa có dự phòng model** — điều này phải ghi rõ trong mức sẵn sàng của loại việc đó.
4. **Ba thay đổi nhỏ trong orchestrator** được đề xuất cho Dev1 (không tự làm): (a) nhận dàn ý có sẵn để bỏ bước dàn ý; (b) kiểm ngân sách trước bước viết, không chỉ trước vòng sửa; (c) ghi `job_type` khi được truyền vào.

### 4.6 Cơ chế tiết kiệm token

| # | Cơ chế | Áp dụng ở đâu | Tiết kiệm đến từ | Trạng thái |
|---|---|---|---|---|
| 1 | **Chỉ chạy bước của loại việc đã chọn** | Mọi loại | Không kéo theo dàn ý/viết khi chỉ cần audit hay khảo sát | Cốt lõi của thiết kế này |
| 2 | **Bỏ bước có điều kiện** | Bước tóm tắt (A, B, H), dàn ý (D, E), sửa (D, F) | Không gọi AI khi kết quả đã có hoặc không đổi | Đã có 1 điểm (vai trò tắt); mở rộng qua `when` |
| 3 | **Dùng lại đầu ra giữa các loại việc** | B→C→D, A→F, brief chủ đề của E | Khảo sát một lần cho nhiều bài; không lập dàn ý hai lần | Mới |
| 4 | **Cache theo website, khóa bằng dấu vân tay đầu vào** | Tóm tắt audit, đặt tên cụm, brief chủ đề, bản tóm tắt hồ sơ thương hiệu | Đầu vào không đổi (cùng mã băm) thì trả kết quả cũ, 0 token | Mới; lưu ngay trong `output_ref`/`data` của phiên trước, không cần hạ tầng cache riêng |
| 5 | **Model rẻ cho bước đơn giản** | Mọi bước `llm` khai `task` | Tóm tắt, đặt tên, phân loại dùng bậc rẻ; chỉ viết bài dùng bậc suy luận | Hạ tầng đã có (`AiModelRoute::TIERS`); cần thêm vài `task` mới (ví dụ `plan`, `cluster`) |
| 6 | **Gom lô** | Đặt tên cụm, brief nhiều bài | Một lời gọi cho 20 cụm thay vì 20 lời gọi; phần chỉ dẫn hệ thống chỉ trả một lần | Mới |
| 7 | **Sửa một phần thay vì viết lại** | F | Chỉ gửi và nhận mục cần sửa | Mới; cần phương thức AI mới (lõi, Dev1) |
| 8 | **Ngữ cảnh gọn và ổn định** | D, E, F | Hồ sơ thương hiệu/lĩnh vực được tóm tắt một lần (cơ chế 4) và đặt ở đầu lời nhắc với nội dung không đổi giữa các lần gọi | Mới. Phần "đặt ở đầu để nhà cung cấp cache lời nhắc" phụ thuộc từng provider — **chưa kiểm**, và nằm trong `AbstractLlmProvider` (lõi, Dev1) |
| 9 | **Ngân sách theo bước, kiểm trước khi gọi** | Mọi bước `llm` | Dừng trước khi tiêu, không phải sau | Mới; hiện chỉ kiểm trước vòng sửa |
| 10 | **Dừng sớm khi không cải thiện** | Vòng sửa | Không sửa vòng 3 khi vòng 2 không khá hơn | **Đã có** (`AgentRunOrchestrator.php:227`) |

Không đề xuất ở giai đoạn này: tìm kiếm vectơ, cache dùng chung giữa các website, tự chọn model theo độ khó của bài. Các cơ chế đó cần số đo thật trước khi đáng đầu tư.

---

## 5. Tự động hóa đầu–cuối, có người can thiệp đúng lúc

Mục tiêu: một chuỗi "khảo sát → kế hoạch → viết → làm mới" có thể tự chạy nối nhau, nhưng con người được hỏi ở **những điểm rẻ và có ảnh hưởng lớn**, và hệ thống tự dừng khi sắp vượt ngân sách.

### 5.1 Mức tự chủ

Tái dùng ba chế độ chiến dịch đã có (`cautious | balanced | autonomous`, cột `campaigns.mode`) và mở rộng ý nghĩa cho việc **nối các loại việc**. Mức tự chủ **không** thay đổi bậc tin cậy xuất bản: ở T1 không gì được tự xuất bản, bất kể mức nào.

| Mức | Nối loại việc | Việc tốn nhiều token | Tình trạng |
|---|---|---|---|
| **Thận trọng** | Mỗi loại việc do người bấm chạy. Hệ thống chỉ gợi ý "bước tiếp theo nên làm". | Luôn hỏi trước, kèm ước lượng chi phí. | Làm được ngay khi có registry |
| **Cân bằng** *(mặc định đề xuất)* | Loại việc **rẻ** (A, B, H, và phần luật của C) tự chạy theo lịch hoặc khi đầu vào quá hạn. Dừng ở cổng duyệt kế hoạch. Sau khi kế hoạch được duyệt, viết bài tự chạy trong hạn mức. | Tự chạy trong ngân sách đã duyệt; hỏi khi ước lượng vượt ngưỡng. | Cần job nền (AG-B10) cho phần "tự chạy theo lịch" |
| **Tự chủ** | Tự nối toàn bộ, kể cả tự chấp nhận kế hoạch khi mọi bài hạng rủi ro thấp. | Tự chạy trong ngân sách. | **Khóa** như hiện nay (`CampaignRunner.php:53`), cần văn bản chính sách của bạn |

### 5.2 Cổng duyệt theo rủi ro

Mỗi cổng ghi một quyết định vào nhật ký bước (như `decision` hiện nay) nên luôn xem lại được vì sao hệ thống dừng hay đi tiếp.

| Cổng | Vị trí | Thận trọng | Cân bằng | Tự chủ (khi mở) | Vì sao đặt ở đây |
|---|---|---|---|---|---|
| Chấp nhận cụm từ khóa | Sau B | Người | Người | Tự, nếu cụm không lệch chủ đề | Sai ở đây kéo sai cả kế hoạch; sửa tốn 0 token |
| **Duyệt kế hoạch** | Sau C | Người | Người | Tự với bài rủi ro thấp | Điểm chặn rẻ nhất trước khi tiêu token viết bài |
| Chạy việc đắt | Trước D/E/F | Người xác nhận chi phí | Tự trong ngân sách | Tự trong ngân sách | Tránh bất ngờ về chi phí |
| Duyệt bản nháp | Sau D/E/F | Người | Người | Người (T1) | Bậc tin cậy T1: không tự xuất bản |
| Chủ đề rủi ro cao / bắt buộc nguồn | Trong D/E/F | Người có chuyên môn | Người có chuyên môn | Người có chuyên môn | Đã có, **không mức nào bỏ được** (`AgentRunOrchestrator.php:249-255`) |
| Duyệt mục kiến thức | Sau G | Người | Người | Người | Kiến thức sai lan ra mọi bài |
| Đầu vào quá hạn | Trước C/D/E | Hỏi | Tự chạy lại nếu là loại rẻ | Tự chạy lại | Cân giữa độ tươi và chi phí |

Khi chờ người, hệ thống dùng cơ chế "chờ bạn bổ sung" đã có (`CampaignWait`, hạn 24 giờ, `CampaignRunner.php:115-136`): việc khác không bị chặn; quá hạn thì tự bỏ qua và ghi giới hạn. Đề xuất mở rộng bảng này cho mọi loại việc (cần migration nhỏ: thêm chủ thể ngoài chiến dịch).

### 5.3 Dừng khi vượt ngân sách

Bốn tầng, tầng trong chạm trước thì dừng trước:

| Tầng | Phạm vi | Khi chạm | Trạng thái |
|---|---|---|---|
| 1. Mỗi lần gọi | Một lời gọi AI | Bỏ kết quả, dừng phiên, ghi quyết định | **Đã có** (`cost_cap_vnd_per_call`, `AgentRunOrchestrator.php:463`) |
| 2. Mỗi bước | Một bước `llm` (kể cả các lần thử lại) | **Không gọi**; ghi `skipped` hoặc dừng tùy bước có bắt buộc không | Mới |
| 3. Mỗi phiên / loại việc | Cả phiên | Dừng ở bước hiện tại, **giữ lại kết quả đã có** (ví dụ giữ nháp), trạng thái "dừng vì ngân sách" | Có một phần (`RunPolicy::budgetVnd`, chỉ kiểm trước vòng sửa) |
| 4. Mỗi ngày | Chiến dịch (đã có) và **website** (mới, cộng mọi loại việc) | Không bắt đầu phiên mới; phiên đang chạy được chạy hết bước hiện tại | Chiến dịch: **đã có** (`CampaignRunner.php:62`). Website: mới, phụ thuộc AG-B09 của Dev1 |

Hai quy tắc đi kèm: (1) **kiểm trước khi gọi** bằng ước lượng từ độ dài đầu vào, không chỉ cộng sau khi đã tiêu; (2) khi còn dưới 20% ngân sách ngày, hệ thống báo trước trên trang Tổng quan thay vì im lặng dừng. Dừng khẩn cấp (`EmergencyStopService`) vẫn đứng trên tất cả và được kiểm ở bước đầu của mọi loại việc.

*Giới hạn hiện tại cần biết:* phiên đang chạy đồng bộ nên "tạm dừng giữa chừng rồi chạy tiếp" chưa làm được; chỉ làm được "dừng hẳn, giữ kết quả". Tiếp tục từ bước dở cần job nền (AG-B10).

---

## 6. Lộ trình theo giai đoạn nhỏ

Mỗi giai đoạn giao được độc lập và có giá trị riêng. Thứ tự được xếp để **những phần không cần Dev1 đi trước**.

| GĐ | Phạm vi | File chạm tới | Phụ thuộc Dev1 | Migration | Cách kiểm thử |
|---|---|---|---|---|---|
| **G0 — Đo và chốt** | Đo token thật của chuỗi hiện tại trên 5–10 bài (dàn ý, viết, từng vòng sửa) từ `agent_run_steps`; chốt các quyết định §7. | Chỉ tài liệu: `docs/seo-agent/reports/…` | Không | Không | Bảng số đo thay cho các ước lượng ở §3; ghi rõ model và ngày đo |
| **G1 — Registry + hiển thị (chỉ đọc)** | `JobTypeRegistry` + 8 file khai báo; tab Pipeline hiện thẻ loại việc với mức sẵn sàng thật; trang phiên chạy lấy danh sách bước từ registry cho `article_single`. **Chưa có loại việc nào mới chạy được.** | Mới: `app/Services/Agent/Pipeline/JobTypeRegistry.php`, `resources/agents/job-types/*.php`. Sửa: `PipelineBlueprint.php`, `PipelineMap.php` và view, `RunFlow.php`, một mục trong `Features/automation.php` | Xác nhận thư mục mới (Q10). Không đụng lõi. | Không | Unit test tính nhất quán của registry (§4.1); test hiện có của `PipelineBlueprint` vẫn qua; phiên cũ hiển thị không đổi |
| **G2 — Cột loại việc** | Phương án P1: `job_type`, `article_id` cho rỗng, `subject_*`, `parent_run_id`, `input_refs`, `output_ref`, `tokens_budget`. Cập nhật model và rà mọi chỗ giả định phiên luôn có bài. | `app/Models/AgentRun.php`; `AgentRunResource`, `ViewAgentRun`, `RunFlow`, `RunActivity`, `AgentOverview` (danh sách cần rà lại khi làm) | **Có**: Dev1 duyệt thay đổi bảng lõi | `2026_11_20_000001_add_job_type_to_agent_runs` — **PR riêng** chỉ gồm migration + Model | Test migration lên/xuống; test dữ liệu cũ nhận `article_single`; test trang phiên chạy với phiên không có bài |
| **G3 — Hai loại việc rẻ đầu tiên** | `site_audit` và `market_research` chạy đồng bộ từ nút bấm, ghi phiên và bước, có bước tóm tắt AI bỏ qua được, có cache theo dấu vân tay. Màn chọn loại việc (§8.1). | Mới: `app/Services/Agent/Pipeline/JobDispatcher.php`, `Runners/SiteAuditRunner.php`, `Runners/MarketResearchRunner.php`. Sửa: `AuditPage.php`, `KeywordsPage.php` (thêm nút, không đổi luật), `AiModelRoute::TASKS` (thêm `task` nếu cần) | Không bắt buộc. Chạy đồng bộ nên audit website lớn có thể chậm — giới hạn số URL cho tới khi có AG-B10. | Không (dùng cột của G2) | Feature test với AI giả lập có nhãn: phiên có đúng bước, bước bị bỏ ghi lý do, lần hai cùng đầu vào tốn 0 token, vượt ngân sách bước thì không gọi |
| **G4 — Kế hoạch và nối vào viết bài** | `content_plan` (brief theo lô, cổng duyệt kế hoạch); lưu brief; `article_single` nhận dàn ý có sẵn; kiểm ngân sách trước bước viết. | Mới: `Runners/ContentPlanRunner.php`. Sửa: `ContentPlanPage.php`. **Lõi:** `AgentRunOrchestrator.php` (3 thay đổi nhỏ ở §4.5) | **Có**: Dev1 review hoặc tự sửa orchestrator | `2026_11_2x_…_add_brief_to_articles` (hoặc bảng brief riêng — quyết định ở §7), PR riêng | Test: bài có brief thì phiên ghi `brief` = `skipped` và không có lời gọi `outline`; test ngân sách chặn trước bước viết; bộ test orchestrator hiện có vẫn qua nguyên vẹn |
| **G5 — Chiến dịch dùng brief chung + làm mới một phần** | Brief chủ đề cho `article_campaign`; `article_refresh` với phân loại phạm vi và nhánh sửa siêu dữ liệu/sửa mục. | Sửa: `CampaignRunner.php`, `CampaignResource.php`. Mới: `Runners/ArticleRefreshRunner.php`. **Lõi:** thêm phương thức sửa một phần ở tầng nhà cung cấp AI (`AbstractLlmProvider` và các adapter) | **Có**: `AbstractLlmProvider` là lõi; lời nhắc mới nằm ngoài `resources/prompts` của Dev1 hoặc do Dev1 thêm | `2026_11_2x_…_add_theme_brief_to_campaigns`, PR riêng | Test phân loại phạm vi theo mã lỗi; test "điểm sau sửa không giảm"; so chi phí làm mới với số đo G0 |
| **G6 — Chạy nền và tự nối** | Mỗi loại việc chạy bằng job hàng đợi; lịch tự chạy cho loại rẻ; mức Cân bằng tự nối; nút Chạy lại/Tạm dừng (`controlsWired`); trần ngày theo website; mở rộng "chờ bạn bổ sung" cho mọi loại việc. | `app/Jobs/*` mới, `routes/console.php`, `PipelineBlueprint::controlsWired`, `CampaignWait` | **Phụ thuộc hoàn toàn** vào AG-B10 (job nền) và AG-B09 (ngân sách/ngắt mạch thật) | Mở rộng `campaign_waits` (chủ thể đa hình), PR riêng | Test job với hàng đợi giả; test dừng khẩn cấp chặn job đang chờ; test trần ngày website |
| **G7 — Đo lường và kho trí tuệ** | `measure` sau khi Search Console nối thật (Task 5.1); `brain_build` ghi phiên theo hợp đồng §3.2-G. | Theo tài liệu riêng của từng phần | Task 5.1; tài liệu kho trí tuệ | Theo tài liệu riêng | Theo tài liệu riêng |

Ghi chú về kiểm thử: trong worktree này thư mục `vendor/` trống nên **không chạy được test tại đây**; cột "Cách kiểm thử" mô tả test cần viết và chạy ở môi trường có đủ phụ thuộc. Mọi test dùng AI giả lập phải giữ nhãn "giả lập" và không được dùng làm bằng chứng cho mức `active` (luật của `FeatureRegistry`).

G1 có thể bắt đầu ngay sau khi bạn chốt danh sách loại việc. G2 là điểm nghẽn đầu tiên cần Dev1.

---

## 7. Quyết định cần bạn chốt và rủi ro

### 7.1 Quyết định cần chốt

| # | Câu hỏi | Đề xuất | Ai chốt |
|---|---|---|---|
| D1 | Danh sách 8 loại việc và tên hiển thị ở §3.1 đã đúng với cách bạn nghĩ về công việc chưa? Có cần gộp (ví dụ khảo sát + kế hoạch) hay tách (từ khóa riêng, đối thủ riêng)? | Giữ 8 loại; từ khóa và đối thủ chung một loại vì dùng chung đầu ra | Bạn |
| D2 | Lưu phiên chạy: mở rộng `agent_runs` (P1) hay bảng mới (P2/P3)? | P1 | Bạn + Dev1 |
| D3 | Mức tự chủ mặc định khi nối các loại việc | Cân bằng: việc rẻ tự chạy, dừng ở cổng duyệt kế hoạch | Bạn |
| D4 | Có bắt buộc duyệt kế hoạch trước khi viết bài theo chiến dịch không? | Có, ở cả Thận trọng và Cân bằng | Bạn |
| D5 | Hạn dùng của đầu ra: audit bao nhiêu ngày, khảo sát bao nhiêu ngày thì coi là cũ? | 14 và 30 ngày | Bạn |
| D6 | Ngân sách mặc định cho từng loại việc và trần ngày cho mỗi website | Chốt **sau G0**, khi có số đo thật | Bạn |
| D7 | Brief của bài lưu ở đâu: cột trên `articles` hay bảng riêng có phiên bản? | Cột JSON trên `articles` trước; tách bảng khi cần lịch sử | Dev1 + Dev2 |
| D8 | Làm mới bài: cho phép sửa một phần (cần phương thức AI mới ở lõi) hay tạm giữ cách viết lại cả bài? | Làm sửa một phần ở G5; trước đó giữ như hiện nay | Bạn + Dev1 |
| D9 | Khi chưa có nguồn dữ liệu từ khóa/đối thủ thật, có cho chạy "khảo sát thị trường" ở dạng giảm cấp (dữ liệu nhập tay) không? | Có, với nhãn giới hạn rõ ràng trên bản khảo sát | Bạn |
| D10 | Thứ tự ưu tiên giữa G3 (audit + khảo sát) và G4 (kế hoạch + nối viết bài) | G3 trước vì không cần Dev1 | Bạn |
| D11 | Thư mục `resources/agents/job-types/` và các lớp mới dưới `app/Services/Agent/Pipeline/` | Xác nhận allowlist | Dev1 |

### 7.2 Rủi ro

| # | Rủi ro | Khả năng | Ảnh hưởng | Giảm thiểu |
|---|---|---|---|---|
| R1 | **Ước lượng token ở §3 sai**; mức tiết kiệm thực thấp hơn kỳ vọng. | Trung bình | Kỳ vọng sai về chi phí | G0 đo trước; không cam kết con số nào cho tới khi có số đo |
| R2 | Đổi `article_id` thành cho rỗng làm vỡ chỗ đang giả định phiên luôn có bài. | Trung bình | Lỗi trên trang Phiên chạy/Tổng quan | G2 đi PR riêng, có test cho phiên không có bài; phương án lùi P3 |
| R3 | Phụ thuộc Dev1 (AG-B10, AG-B09, review orchestrator) trong khi Dev1 có hạn 09/12 cho việc khác. | Cao | G4–G6 trượt | G1 và G3 không cần Dev1; các thay đổi lõi gói nhỏ, mô tả sẵn ở §4.5 |
| R4 | Chạy đồng bộ: audit website lớn hoặc khảo sát nhiều cụm làm treo yêu cầu web. | Cao (trước G6) | Trải nghiệm kém, có thể hết thời gian | Giới hạn số URL/số cụm mỗi phiên ở G3; ghi rõ giới hạn trên giao diện |
| R5 | Thêm nhiều runner tạo ra mã lặp (ghi bước, thử lại, gọi AI). | Cao | Nợ kỹ thuật, hành vi không đồng nhất | Tách phần chung khỏi orchestrator với sự review của Dev1 (§4.5 mục 3); chấp nhận lặp có kiểm soát trước đó |
| R6 | Cache trả kết quả cũ khi đầu vào thực ra đã đổi (dấu vân tay thiếu trường). | Thấp–Trung bình | Tóm tắt/brief lỗi thời | Dấu vân tay gồm cả phiên bản lời nhắc và model; mỗi kết quả lấy từ cache ghi rõ "dùng lại từ phiên #…"; có nút "chạy lại không dùng cache" |
| R7 | Dùng lại brief chủ đề làm các bài trong chiến dịch giống nhau. | Trung bình | Nội dung trùng lặp, bất lợi SEO | Brief chủ đề chỉ chứa giọng và ý chung; mỗi bài vẫn có brief riêng; cân nhắc thêm luật kiểm trùng giữa các bài (ngoài phạm vi tài liệu này) |
| R8 | 8 thẻ loại việc làm người dùng mới bị ngợp. | Trung bình | Khó bắt đầu | Màn chọn việc gợi ý "bước tiếp theo" theo dữ liệu đang có; loại chưa sẵn sàng hiện mờ kèm lý do |
| R9 | Tự nối ở mức Cân bằng tiêu ngân sách vào việc rẻ nhưng lặp nhiều (audit hằng ngày). | Thấp | Chi phí âm ỉ | Trần ngày theo website (tầng 4); việc rẻ phần lớn là luật, 0 token; lịch mặc định thưa |
| R10 | Bản đồ 9 giai đoạn và danh sách loại việc lệch nhau theo thời gian. | Trung bình | Người dùng lại thấy hai câu chuyện | Một nguồn duy nhất: giai đoạn tính từ `job_types`, có test nhất quán |

---

## 8. Wireframe

Các hình dưới là **phác thảo bố cục**, số liệu trong hình là ví dụ minh họa, không phải số thật.

### 8.1 Màn chọn loại việc (tab Pipeline của Đội AI)

```
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Đội AI ▸ Pipeline                                   Website: [ addp.vn ▾ ]               │
│ Bạn muốn đội AI làm việc gì?                Ngân sách hôm nay: ▓▓▓▓░░░░░░ 62.000/150.000 ₫│
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ GỢI Ý BƯỚC TIẾP THEO                                                                     │
│ ▸ Bản khảo sát gần nhất đã 41 ngày. Nên khảo sát lại trước khi lập kế hoạch.  [Khảo sát] │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ HIỂU VÀ KHẢO SÁT                                                                         │
│ ┌───────────────────────────┐ ┌───────────────────────────┐ ┌───────────────────────────┐│
│ │ Audit website             │ │ Khảo sát thị trường       │ │ Đo lường                  ││
│ │ ● Dữ liệu minh họa        │ │ ● Dữ liệu minh họa        │ │ ○ Chưa kết nối            ││
│ │ Ra: Báo cáo audit         │ │ Ra: Bản khảo sát          │ │ Ra: Báo cáo hiệu quả      ││
│ │ 6 bước · 1 bước AI (rẻ)   │ │ 7 bước · 2 bước AI (rẻ)   │ │ Cần nối Search Console    ││
│ │ Chi phí: rất thấp         │ │ Chi phí: thấp             │ │                           ││
│ │ Lần gần nhất: 3 ngày trước│ │ Lần gần nhất: 41 ngày     │ │                           ││
│ │ [ Chạy ]  [ Xem bước ]    │ │ [ Chạy ]  [ Xem bước ]    │ │ [ Vì sao chưa chạy? ]     ││
│ └───────────────────────────┘ └───────────────────────────┘ └───────────────────────────┘│
│ LẬP KẾ HOẠCH                                                                             │
│ ┌───────────────────────────┐                                                            │
│ │ Lập kế hoạch nội dung     │   Dùng: Bản khảo sát 30/09 (đã cũ)                         │
│ │ ◐ Đang phát triển         │   Ra: Kế hoạch (bài kèm brief) — cần bạn duyệt             │
│ │ 6 bước · 1 bước AI (rẻ)   │                                                            │
│ │ [ Chạy ]  [ Xem bước ]    │                                                            │
│ └───────────────────────────┘                                                            │
│ VIẾT VÀ LÀM MỚI                                                                          │
│ ┌───────────────────────────┐ ┌───────────────────────────┐ ┌───────────────────────────┐│
│ │ Viết bài đơn lẻ           │ │ Viết theo chiến dịch      │ │ Làm mới bài cũ            ││
│ │ ◐ Đang phát triển         │ │ ◐ Đang phát triển         │ │ ◐ Đang phát triển         ││
│ │ Ra: Bản nháp chờ duyệt    │ │ Ra: Loạt bản nháp         │ │ Ra: Bản sửa đổi           ││
│ │ 7 bước · tới 5 lần gọi AI │ │ 2 chiến dịch đang chạy    │ │ 4 đề xuất từ audit        ││
│ │ Chi phí: cao nhất         │ │ Chi phí: theo hạn mức ngày│ │ Chi phí: thấp → vừa       ││
│ │ [ Chọn bài ]              │ │ [ Mở chiến dịch ]         │ │ [ Chọn bài ]              ││
│ └───────────────────────────┘ └───────────────────────────┘ └───────────────────────────┘│
│ KIẾN THỨC                                                                                │
│ ┌───────────────────────────┐                                                            │
│ │ Xây/cập nhật kho trí tuệ  │   3 khoảng trống kiến thức được ghi khi viết bài           │
│ │ [ Mở kho trí tuệ ]        │                                                            │
│ └───────────────────────────┘                                                            │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ TOÀN CẢNH  Hiểu web ─ Audit ─ Từ khóa ─ Đối thủ ─ Kế hoạch ─ Viết ─ Chất lượng ─ Nháp ─ Đo│
│               ●        ●        ●         ●          ◐         ◐        ●          ◐     ○│
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

### 8.2 Xác nhận trước khi chạy (cửa sổ trượt, khi bấm "Chạy" hoặc "Xem bước")

```
┌────────────────────────────────────────────────────────────┐
│ Khảo sát thị trường                                    [×] │
│ Biết nên viết về từ khóa nào và đối thủ đang hơn ở đâu.    │
├────────────────────────────────────────────────────────────┤
│ ĐẦU VÀO                                                    │
│  Từ khóa hạt giống:  148 từ (nhập tay/CSV)   [ Thêm ]      │
│  Đối thủ:            chưa có nguồn dữ liệu → bỏ bước 5–7   │
├────────────────────────────────────────────────────────────┤
│ CÁC BƯỚC SẼ CHẠY                              Tốn token?   │
│  1 An toàn                                    Không        │
│  2 Gom và loại trùng từ khóa                  Không        │
│  3 Gom cụm theo luật                          Không        │
│  4 Đặt tên cụm, gán ý định        AI · bậc Rẻ  ~ thấp      │
│  5 Lấy dữ liệu đối thủ            (bỏ qua: chưa có nguồn)  │
│  6 Tính khoảng trống              (bỏ qua)                 │
│  7 Nhận định khoảng trống         (bỏ qua)                 │
├────────────────────────────────────────────────────────────┤
│ Ước lượng: khoảng 2.000 ₫ · trần phiên 10.000 ₫            │
│ Kết quả: Bản khảo sát — cụm ở trạng thái "đề xuất",        │
│ cần bạn chấp nhận trước khi dùng để lập kế hoạch.          │
│ Giới hạn: không có dữ liệu đối thủ nên không có khoảng trống│
│                                                            │
│ [ ] Không dùng kết quả đã lưu (chạy lại từ đầu)            │
│                              [ Hủy ]   [ Chạy khảo sát ]   │
└────────────────────────────────────────────────────────────┘
```

### 8.3 Màn theo dõi một phiên chạy

```
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Phiên #214 · Làm mới bài cũ · "Cách chọn máy lọc nước cho gia đình"                      │
│ ● Đang chạy · bắt đầu 09:14 · do Lan bấm chạy · AI thật                                  │
│ Ngân sách phiên: ▓▓▓░░░░░░░ 2.300 / 8.000 ₫        Token: 3.100 / 12.000                 │
│                                                              [ Dừng phiên ]              │
├───────────────────────────────┬──────────────────────────────────────────────────────────┤
│ CÁC BƯỚC                      │ BƯỚC 4 · Sửa các mục có lỗi                              │
│                               │ Vai trò: Người viết · Model: bậc Chuẩn                   │
│ ✔ 1 An toàn            luật   │ Trần bước: 8.000 token · đã dùng 2.400                   │
│ ✔ 2 Chấm bản hiện tại  luật   │                                                          │
│     Điểm 71 · 2 lỗi chặn      │ VÌ SAO CHẠY BƯỚC NÀY                                     │
│ ✔ 3 Phân loại phạm vi  luật   │ Bước 3 xếp phạm vi "sửa một số mục": 2 lỗi chặn nằm ở    │
│     → Sửa một số mục          │ mục 2 và mục 5, không cần viết lại cả bài.               │
│ ⤼ – Sinh lại tiêu đề   bỏ qua │                                                          │
│     (phạm vi không phải       │ ĐANG GỬI CHO AI                                          │
│      chỉ siêu dữ liệu)        │ 2 mục cần sửa (không gửi 6 mục còn lại)                  │
│ ◉ 4 Sửa các mục có lỗi  AI    │ Lỗi: LINK01 thiếu liên kết nội bộ · KB01 số liệu cũ      │
│ ○ 5 Chấm lại           luật   │                                                          │
│ ○ 6 Bàn giao bản sửa   luật   │ ĐẦU VÀO CỦA PHIÊN                                        │
│                               │ ▸ Đề xuất từ Audit ngày 07/11 (phiên #198)               │
│ Chú thích: ✔ xong  ◉ đang chạy│ ▸ Kiến thức đã duyệt: 3 mục                              │
│ ○ chưa tới  ⤼ bỏ qua  ✖ lỗi   │                                                          │
├───────────────────────────────┴──────────────────────────────────────────────────────────┤
│ KẾT QUẢ (hiện khi xong)                                                                  │
│ Bản sửa đổi v4 · điểm 71 → 88 · đổi 2 mục · [ Xem so sánh trước/sau ]  [ Duyệt ] [ Trả ] │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ CẦN BẠN                                                                                  │
│ Chưa có gì. Khi xong, bản sửa đổi sẽ vào hàng chờ duyệt (bậc T1: không tự xuất bản).     │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

Ghi chú về §8.3: thanh tiến độ "đang chạy" theo từng bước chỉ có ý nghĩa khi phiên chạy nền (G6). Trước đó phiên chạy đồng bộ, người dùng thấy màn này ở trạng thái đã xong; bố cục giữ nguyên.

### 8.4 Phiên dừng vì ngân sách (phần đầu màn 8.3)

```
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Phiên #221 · Viết bài đơn lẻ · "Máy lọc nước RO là gì"                                   │
│ ■ Dừng vì ngân sách · 10:02–10:05                                                        │
│ Đã chi 49.200 / 50.000 ₫. Bước "Sửa lần 2" ước tốn 9.000 ₫ nên hệ thống KHÔNG gọi AI.    │
│ Đã giữ: bản nháp v2 (còn 1 lỗi chặn: META01) — lưu để bạn xem, chưa vào hàng chờ duyệt.  │
│ [ Xem bản nháp ]   [ Tự sửa tay rồi chấm lại (0 ₫) ]   [ Cấp thêm 10.000 ₫ và chạy tiếp ]│
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## Phụ lục — Những điều tài liệu này chưa kiểm

- Chưa đo token thật của bất kỳ bước nào; mọi con số ở §3 và §8 là ước lượng hoặc ví dụ.
- Chưa rà hết các chỗ trong mã giả định `agent_runs.article_id` luôn có giá trị (danh sách ở §4.2 và G2 là ước chừng).
- Chưa kiểm khả năng cache lời nhắc của từng nhà cung cấp AI (cơ chế 8 ở §4.6).
- Chưa đọc mã trong `app/Services/Pilot` và `resources/prompts` (mã của Dev1); thiết kế này không yêu cầu sửa hai nơi đó, nhưng G5 có thể cần lời nhắc mới do Dev1 thêm.
- Tài liệu `THIET_KE_KHO_TRI_TUE_TU_DONG.md` chưa tồn tại trong nhánh này tại thời điểm viết; mục G ở §3.2 chỉ là hợp đồng ghép nối đề xuất và cần khớp lại khi tài liệu đó có.
- `HANDOVER_2026-11-10.md` không có trong worktree này; nội dung §5 được đọc từ bản ở checkout chính (`addp-autoseo/docs/seo-agent/`).
