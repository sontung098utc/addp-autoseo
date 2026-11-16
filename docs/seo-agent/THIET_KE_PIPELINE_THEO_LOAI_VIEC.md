# THIẾT KẾ — PIPELINE THEO LOẠI VIỆC, BÁM THEO CHUỖI LÀM RA MỘT BÀI

> Phiên bản 0.2 — ngày 10/11/2026 — nhánh `feat/m09/ag-s27/dev2/article-workspace` (sau commit `c9191e0`).
> Đây là **tài liệu thiết kế, chưa có dòng mã nào viết theo nó**. Phần "hiện trạng" đã đối chiếu lại với mã (có dẫn `file:dòng`). Con số token là **ước lượng chưa đo**. Chỗ chưa kiểm được ghi ở Phụ lục.
> Liên quan: `THIET_KE_KHO_TRI_TUE_TU_DONG.md` (chi tiết Kho trí tuệ; ở đây chỉ nêu điểm ghép), `AI_AUTOMATION_DESIGN_ANALYSIS.md` §7, `HANDOVER_2026-11-10.md` §5.

## Tóm tắt

Trang bài viết giờ hiển thị bài theo **chuỗi 6 mắt xích**: Từ khóa → Kế hoạch → Nghiên cứu và nguồn → Viết → Kiểm và duyệt → Xuất bản (sau đó là Đo lường). Tài liệu này xếp mọi "loại việc" của đội AI vào chuỗi đó. Quy tắc duy nhất: **một loại việc chỉ tồn tại khi đầu ra của nó được ghi vào một bảng có thật, hiện ở một màn có thật, và có người hoặc bước sau dùng tiếp.** Áp quy tắc này, 8 loại việc ở bản 0.1 còn **7**, và ba bước AI "tóm tắt cho đẹp" bị bỏ. Tài liệu cũng chỉ ra 9 chỗ chuỗi đang đứt trong mã và giai đoạn nào vá.

---

## 1. Hiện trạng (đã kiểm trong mã)

### 1.1 Từng mắt xích: cái gì thật, cái gì minh họa

| Mắt xích | Dữ liệu | Thật | Minh họa / chưa có | Dẫn chứng |
|---|---|---|---|---|
| 1. Từ khóa | `keywords` (volume, difficulty, intent, source, cluster_id, status), `keyword_clusters` | Nhập CSV, nhập tay; thêm từ khoảng trống đối thủ (`source=competitor`). Volume để trống khi nguồn không có. | Gom cụm là **bản minh họa** (`MockClusterer`, không AI). Đề xuất cụm lưu trong cache 1 ngày, không vào bảng. **Không có mã nào ghi `source=ai`**; "AI đề xuất" mới chỉ là nhãn. | `KeywordService.php:139-142`; `EloquentKeywordRepository.php:106-108`; `CompetitorStore.php:133` |
| 2. Kế hoạch | `articles.kind/origin/origin_ref/keyword_id/cluster_id/priority/campaign_id` | Tạo bài `planned` từ cụm hoặc từ khoảng trống đối thủ, có nối `keyword_id`. Đề xuất làm mới từ audit thành bài `kind=refresh, origin=audit`. | Gợi ý tiêu đề là minh họa (`MockTitleSuggester`). Không có bước AI lập kế hoạch. | `PlannedArticleCreator.php:62-64`; `AuditSuggestionService.php:106-107`; `MockTitleSuggester.php:5` |
| 3. Nghiên cứu và nguồn | Bước `brief` và `write` trong `agent_run_steps` (`data.knowledge`, `data.images`, `data.internal_links`), `knowledge_item_usages`, `brand_profiles`, `brain_sources` | Lập dàn ý bằng AI. Truy xuất kiến thức **đã duyệt**, chọn ảnh, gợi ý liên kết nội bộ: đều bằng luật, 0 token. Thiếu kiến thức thì ghi `knowledge_gaps`. | Không có bước riêng tên "nghiên cứu": dữ liệu nằm lẫn trong bước viết. Không tìm nguồn web. | `AgentRunOrchestrator.php:191`, `:292-301`; `KnowledgeContext.php:82`, `:110`; `ContextAssets.php:57-58` |
| 4. Viết | `article_versions`, `article_version_media` | Viết bằng AI, làm sạch HTML, lưu phiên bản; ảnh bìa gắn từ thư viện. | AI **chỉ chọn ảnh có sẵn**, không tạo ảnh. | `AgentRunOrchestrator.php:304`; `ImageSelector.php:11` |
| 5. Kiểm và duyệt | `QualityReport` (P0/P1/P2), `ReviewWorkflow` | Chấm bằng luật, 0 token; sửa tối đa 3 vòng, dừng sớm khi không khá hơn; người duyệt. | Nguồn chỉ được kiểm ở mức cả bài và chỉ khi Trí tuệ ở mức "bắt buộc nguồn" (luật EVID01). | `AgentRunOrchestrator.php:330`, `:227`; `EvidenceRule.php:13-17` |
| 6. Xuất bản | `publications` | Xếp lịch, đăng, đối soát. Bậc T1: đội AI chỉ tới bản nháp. | — | `PipelineBlueprint.php:43` |
| Đo lường | — | Có mã nối URL Search Console với bài đã đăng. | Nguồn dữ liệu ở production là **rỗng**; đề xuất cập nhật lưu tạm ở kho minh họa; trang bài viết không có số liệu nào. | `GscService.php:15`; `UpdateProposals.php:10`; `PageArticleLinker.php` |

### 1.2 Bộ máy chạy

| Điểm | Thực tế | Dẫn chứng |
|---|---|---|
| Chỉ một chuỗi chạy | `AgentRunOrchestrator::run(Article…)`: an toàn → rủi ro → dàn ý → viết → chấm → sửa → bàn giao nháp. Cố định, không có tham số chọn chuỗi. Chỉ bỏ được dàn ý khi vai trò Chiến lược tắt. | `AgentRunOrchestrator.php:94`, `:183-186` |
| Lời gọi AI | Ba loại: `outline` (rẻ), `write` (suy luận), `repair` (chuẩn). Route `summary` và `knowledge` (rẻ) có khai báo; chỉ `KnowledgeAiIngest` dùng, chạy ngoài phiên chạy. | `AiModelRoute.php:12-19`; `KnowledgeAiIngest.php:68` |
| Ngân sách | Một con số cho cả phiên (50.000 ₫), **chỉ kiểm trước mỗi vòng sửa**. Có thêm trần mỗi lời gọi. | `RunPolicy.php:10`; `AgentRunOrchestrator.php:210`, `:463` |
| Chiến dịch | `CampaignRunner::tick` kiểm hạn mức ngày, chọn bài kế tiếp, rồi gọi **đúng chuỗi trên**. Nó là bộ hẹn giờ, không phải một chuỗi khác. | `CampaignRunner.php:31`, `:90` |
| Kích hoạt | Đồng bộ: nút "Giao đội AI", lệnh `agent:run`, lệnh `agent:tick` mỗi 15 phút. Chưa có job hàng đợi cho đội AI. | `routes/console.php:26` |
| Audit | Quét thật chỉ chạy bằng **lệnh dòng lệnh**. Nút "Quét lại" trên giao diện chạy trên **bộ trang mẫu**. Sinh đề xuất làm mới từ dữ liệu quét là thật. | `AuditWebsiteCommand.php:72`; `AuditPage.php:114`, `:171` |
| Phiên chạy bắt buộc có bài | `agent_runs.article_id` không cho rỗng; `agent_run_steps.stage` dài 20 ký tự. | migration `2026_11_07_000001:18`, `:43` |
| Bản đồ 9 giai đoạn | Sáu giai đoạn có `run_stages` rỗng: chỉ là nhãn. Nút điều khiển tắt (`controlsWired()`). | `PipelineBlueprint.php:19`, `:28-44` |
| Trang chi tiết phiên, trang bài | Danh sách chặng viết cứng ở hai nơi. | `RunFlow.php:18`; `ViewArticle.php:44-47` |

Mức bằng chứng: toàn bộ phần đội AI "mới ở mức 1–2" (test giả lập, chạy thử cục bộ) theo HANDOVER §5. Chưa có số đo token thật.

### 1.3 Chín chỗ chuỗi đang đứt

| # | Chỗ đứt | Đã kiểm ở đâu | Hậu quả người dùng thấy | Vá ở |
|---|---|---|---|---|
| Đ1 | Bài tạo tay không có `keyword_id`, không có `origin`. Bài do chiến dịch tạo từ danh sách từ khóa cũng vậy. | Form tạo bài không có hai trường này (`CreateArticle.php`); `CampaignRunner.php:88` | Tab "Từ khóa và kế hoạch" phải dò theo tên (`ViewArticle.php:146-151`); không khớp thì báo "bài được tạo mà không qua bước nghiên cứu từ khóa" | G0 |
| Đ2 | Không có AI nào tìm hay đề xuất từ khóa; gom cụm là minh họa | Xem 1.1 dòng 1 | Kho từ khóa chỉ có thứ người nhập | G3 |
| Đ3 | Không có bước "nghiên cứu" riêng; dữ liệu nghiên cứu nằm trong bước viết | `ViewArticle.php:163-164` đọc từ bước `write` | Không thấy được AI đã chuẩn bị gì trước khi viết; không dừng được giữa nghiên cứu và viết | G4 |
| Đ4 | Chưa tìm nguồn web hay nguồn tổ chức chuyên ngành | Không có mã tìm web; trang bài tự ghi điều này (`workspace.blade.php:344`) | Nguồn duy nhất được kiểm soát là Kho trí tuệ và hồ sơ doanh nghiệp | Tài liệu Kho trí tuệ, giai đoạn 3; ghép ở G6 |
| Đ5 | Nguồn AI nêu (`sources_cited`) không được kiểm chứng | Lấy thẳng từ phản hồi AI (`AgentRunOrchestrator.php:322`). EVID01 chỉ kiểm cả bài, chỉ khi "bắt buộc nguồn" | Bài thường có thể nêu nguồn không tồn tại mà không bị cờ | G4 |
| Đ6 | Không có danh mục sản phẩm gắn vào bài | Không có bảng sản phẩm. `brand_profiles` chỉ có lĩnh vực, giọng, thông điệp, từ cấm | Bài không tự nhắc đúng sản phẩm liên quan của công ty | G4 (dùng mục kiến thức, không thêm bảng — cần chốt Q5) |
| Đ7 | AI chỉ chọn ảnh từ thư viện, chưa tạo ảnh | `ImageSelector.php:11`; `workspace.blade.php:398` | Thư viện trống thì bài không có ảnh | G6, nếu bạn chọn làm (Q6) |
| Đ8 | Số liệu Search Console chưa nối về từng bài | Xem 1.1 dòng Đo lường | Không biết bài nào hiệu quả; không có đề xuất làm mới theo hiệu quả | G6 (cần Task 5.1) |
| Đ9 | Quét audit thật không bấm được từ giao diện | Xem 1.2 dòng Audit | Đề xuất làm mới dựa trên lần quét bằng dòng lệnh gần nhất | G3 (bản giới hạn), G6 (chạy nền) |

---

## 2. Vấn đề

**Tốn token.** (1) Đơn vị nhỏ nhất là cả chuỗi viết: không chạy riêng được "chỉ dàn ý" hay "chỉ chấm lại bản sửa tay" (chấm là luật, đáng lẽ 0 token). (2) Làm mới bài cũ vẫn lập dàn ý và viết lại toàn bộ. (3) Ngân sách không kiểm trước bước viết, là bước đắt nhất. (4) Nếu sau này nối khảo sát và audit vào **mỗi** bài thì chi phí nhân theo số bài, trong khi kết quả khảo sát của một website ít đổi trong vài tuần. Điểm (4) là rủi ro của thiết kế, chưa phải chi phí đang phát sinh.

**Khó hiểu.** Bản đồ nói 9 giai đoạn, máy chạy 3. Người dùng không biết "muốn làm việc X thì bấm đâu, kết quả hiện ở đâu, ai dùng tiếp". Một số màn tạo ra thứ không nối vào bài nào (đề xuất cụm trong cache, gợi ý tiêu đề minh họa).

---

## 3. Loại việc, xếp theo chuỗi

### 3.1 Quy tắc giữ hay bỏ

Một loại việc, và từng bước AI trong nó, phải trả lời đủ ba câu: **ghi vào bảng/cột nào, hiện ở màn nào, ai dùng tiếp.** Không trả lời được thì bỏ.

| Thứ ở bản 0.1 | Quyết định | Lý do |
|---|---|---|
| Loại việc `article_campaign` | **Gộp** vào `article_write` | Chiến dịch chỉ là cách hẹn giờ và hạn mức để gọi chuỗi viết bài (`CampaignRunner.php:90`). Đầu ra giống hệt viết bài đơn lẻ. |
| "Brief chủ đề" dùng chung cho chiến dịch | **Bỏ** | Không có chỗ lưu, không có màn hiện, và dễ làm các bài giống nhau. Giọng và ý chung đã có ở Trí tuệ và hồ sơ doanh nghiệp. |
| Bước AI "tóm tắt 3 việc nên làm" trong audit | **Bỏ** | Không bước nào dùng tiếp. Danh sách đề xuất làm mới đã xếp theo ưu tiên. |
| Bước AI "nhận định khoảng trống đối thủ" | **Bỏ** | Như trên. Điểm ưu tiên của khoảng trống (`priority_score`) đã có. |
| Bước AI "tóm tắt hiệu quả" trong đo lường | **Bỏ** | Như trên. Đầu ra có ích duy nhất là đề xuất làm mới. |
| `site_audit` và `measure` | **Giữ riêng**, đều 0 token | Hai nguồn tín hiệu khác nhau, cùng đổ về một chỗ: bài làm mới trong Kế hoạch. |

Còn lại **7 loại việc**: `market_research`, `site_audit`, `measure`, `content_plan`, `article_write`, `article_refresh`, `brain_build`.

### 3.2 Bảng chính: mỗi loại việc ghi gì, hiện đâu, ai dùng tiếp

Token tương đối: một bài viết không sửa = 1,0 (ước lượng).

| Loại việc | Mắt xích | Đầu vào (bảng) | Đầu ra GHI vào | HIỆN ở đâu, người dùng thấy gì | Ai dùng tiếp | Token |
|---|---|---|---|---|---|---|
| `market_research` Khảo sát thị trường | 1 | `keywords` hạt giống; `keyword_gaps` (CSV đối thủ); `brand_profiles`; sau này Search Console | `keywords` mới (`source=ai`, `intent`, `cluster_id`; `volume` **để trống nếu không có nguồn số liệu**); `keyword_clusters`; `keyword_gaps.priority_score` | Trang **Từ khóa**: từ mới có nhãn "AI đề xuất", bảng cụm. Trang **Đối thủ**: khoảng trống xếp ưu tiên. Tab **"Từ khóa và kế hoạch"** của bài: lượt tìm, cụm, các từ cùng cụm chưa có bài | `content_plan` | 0,1–0,3 |
| `site_audit` Audit website | 2 (tạo việc làm mới) | `crawled_urls`, `seo_audits` | `audit_suggestions`; khi người chấp nhận: `articles` (`kind=refresh, origin=audit`) | Trang **Audit**: nhóm lỗi, đề xuất làm mới. Trang **Kế hoạch**. Tab "Từ khóa và kế hoạch": "bài sinh ra từ Kiểm tra SEO" | `article_refresh` | 0 |
| `measure` Đo lường | sau 6, quay về 2 | Search Console; `publications.published_url` | Số liệu theo bài; đề xuất làm mới (`origin=gsc`). **Chưa có bảng** — cần migration | Trang **Search Console**; mắt xích thứ 7 trên trang bài (lượt hiển thị, nhấp, vị trí) | `article_refresh`; `market_research` (từ khóa `source=gsc`) | 0 |
| `content_plan` Lập kế hoạch | 2 | `keywords`, `keyword_clusters`, `articles` đã có, `brand_profiles` | `articles` trạng thái `planned` với `keyword_id`, `cluster_id`, `origin=plan`, `priority`; `keywords.status=planned` | Trang **Kế hoạch nội dung**: danh sách bài chờ duyệt kế hoạch. Tab "Từ khóa và kế hoạch" của từng bài | `article_write` (trực tiếp hoặc qua chiến dịch: `CampaignRunner::nextPlanned`) | 0–0,1 |
| `article_write` Viết bài | 3, 4, 5 | `articles`; `knowledge_items` đã duyệt; `brand_profiles`; `brain_sources`; `media_assets` | Bước `research`, `brief`, `write`, `grade`, `repair`, `handoff` trong `agent_run_steps`; `article_versions` (kèm `sources_cited`); `knowledge_item_usages`; `article_version_media`; `knowledge_gaps` | Tab **"Nghiên cứu và nguồn"**: dàn ý, kiến thức đã dùng, nguồn, ảnh và liên kết được chọn. Tab **"Nội dung"**: các phiên bản, ảnh, điểm P0/P1/P2. Tab **"Hoạt động"**: từng bước và chi phí | Người duyệt → Xuất bản; `brain_build` (qua khoảng trống kiến thức) | 1,0–2,5 |
| `article_refresh` Làm mới bài | 3, 4, 5 (bài đã có) | `articles` (`kind=refresh`), `audit_suggestions.issue_codes`, phiên bản hiện tại | Phiên bản mới trong `article_versions`; các bước trong `agent_run_steps`; `audit_suggestions.status=da_xong` | Tab "Nội dung": phiên bản mới, so sánh trước/sau. Trang Audit: đề xuất chuyển "đã xong" | Người duyệt → Xuất bản | 0,2–1,0 |
| `brain_build` Xây kho trí tuệ | cấp cho 3 | Tài liệu, website; `knowledge_gaps` | `knowledge_items` trạng thái `proposed` | Trang **Kho trí tuệ**: mục chờ duyệt | `article_write`, `article_refresh` (chỉ đọc mục **đã duyệt**) | Theo tài liệu riêng |

### 3.3 Các bước của từng loại việc

Ký hiệu: **[L]** bước luật, 0 token; **[AI-rẻ/chuẩn/suy luận]** bước gọi model theo bậc.

**`market_research`.** 1 An toàn [L] → 2 Gom từ khóa từ kho và khoảng trống đối thủ, loại trùng [L] → 3 Đề xuất từ khóa mới quanh hạt giống và lĩnh vực của website [AI-rẻ], *bỏ qua nếu người dùng chỉ muốn gom cụm* → 4 Gom cụm và gán ý định tìm kiếm [AI-rẻ], gom lô; *chỉ chạy cho từ chưa có cụm* → 5 Tính điểm ưu tiên khoảng trống [L] → 6 Ghi vào `keywords` và `keyword_clusters` ở dạng **đề xuất chờ chấp nhận** [L]. Cổng: bạn chấp nhận, gộp, tách cụm (thao tác đã có ở trang Từ khóa). Giới hạn phải nói thẳng trên màn: AI **không biết lượt tìm kiếm**. Chưa có nguồn số liệu thì cột lượt tìm để trống, không điền số đoán. Câu "từ khóa được tìm nhiều nhất" chỉ trả lời được khi có CSV, Search Console hoặc một nguồn dữ liệu từ khóa (Q4).

**`site_audit`.** 1 An toàn [L] → 2 Quét [L] → 3 Nhóm lỗi, chấm điểm [L] → 4 Sinh đề xuất làm mới, khớp URL với bài [L]. Không có bước AI. Cổng: bạn chọn đề xuất nào thành bài làm mới.

**`measure`.** 1 Kiểm kết nối [L]; chưa nối thì dừng → 2 Kéo số liệu [L] → 3 Ghép với bài đã đăng [L] → 4 Phát hiện bài tụt theo ngưỡng [L] → 5 Sinh đề xuất làm mới [L]. Khai báo ở trạng thái "Chưa kết nối" cho tới khi Task 5.1 xong.

**`content_plan`.** 1 An toàn [L] → 2 Bỏ từ khóa đã có bài [L] → 3 Kiểm lệch chủ đề với hồ sơ doanh nghiệp [L] (đã có `BrandProfileData::looksOffTopic`) → 4 Xếp ưu tiên theo lượt tìm, khoảng trống, độ phủ cụm [L] → 5 Tạo bài `planned` qua `PlannedArticleCreator` [L]. Bản đầu **không có bước AI**: tiêu đề và góc viết thuộc về bước dàn ý của mắt xích 3, làm ở đây sẽ trả tiền hai lần. Cổng: **bạn duyệt kế hoạch** trước khi bài vào hàng chờ viết. Đây là điểm chặn rẻ nhất: sửa kế hoạch 0 token, sửa bài đã viết thì tốn.

**`article_write`.** 1 An toàn [L] → 2 Xét rủi ro [L] → 3 Dàn ý [AI-rẻ], *bỏ qua nếu bài đã có dàn ý được duyệt hoặc vai trò Chiến lược tắt* → 4 **Nghiên cứu** [L] (bước mới, tách khỏi bước viết): lấy kiến thức đã duyệt, hồ sơ doanh nghiệp, sản phẩm liên quan, danh sách nguồn được phép trích, ảnh, liên kết nội bộ; ghi khoảng trống kiến thức → 5 Viết [AI-suy luận] → 6 Chấm bằng luật, gồm **luật kiểm nguồn mới** [L] → 7 Sửa [AI-chuẩn], 0–3 vòng, chỉ khi còn lỗi chặn → 8 Bàn giao nháp [L]. Cổng: người duyệt bản nháp; chủ đề rủi ro cao hoặc "bắt buộc nguồn" giữ cho người có chuyên môn (đã có, không mức tự chủ nào bỏ được). Chiến dịch là một cách kích hoạt loại việc này, với hạn mức ngày đã có.

Nghiên cứu đặt **sau** dàn ý vì mã hiện truy xuất kiến thức theo các mục của dàn ý (`AgentRunOrchestrator.php:292-293`). Trên màn hình, mắt xích 3 vẫn gồm cả dàn ý và nghiên cứu. Việc tách bước là sửa lõi, do Dev1 quyết (Q11).

**`article_refresh`.** 1 An toàn [L] → 2 Chấm bản hiện tại [L] → 3 Phân loại phạm vi sửa theo mã lỗi [L]: (a) chỉ tiêu đề SEO và mô tả meta, (b) một số mục, (c) viết lại → 4a Sinh lại siêu dữ liệu [AI-rẻ]; 4b Sửa các mục có lỗi [AI-chuẩn], chỉ gửi mục cần sửa; 4c chạy như `article_write` → 5 Chấm lại [L] → 6 Bàn giao phiên bản mới [L]. Cổng: điểm sau sửa không thấp hơn trước; người duyệt xem so sánh. Nhánh 4a, 4b cần phương thức AI mới ở tầng nhà cung cấp (lõi của Dev1). Trước khi có, chạy nhánh (c) như hiện nay.

**`brain_build`.** Xem §3.4.

### 3.4 Ghép với Kho trí tuệ (chỉ điểm ghép)

Chi tiết thuộc `THIET_KE_KHO_TRI_TUE_TU_DONG.md`. Phía pipeline cam kết bốn điểm:

1. **Mắt xích 3 đọc gì từ kho.** Bước nghiên cứu đọc: mục kiến thức **đã duyệt** của các Trí tuệ áp dụng cho bài (`KnowledgeContext`, `BrainContext`); hồ sơ viết của Trí tuệ; Sổ nguồn đã xác nhận (`brain_sources`). Mục chưa duyệt và nguồn mới ở dạng gợi ý không bao giờ vào bài.
2. **Mắt xích 3 ghi gì cho kho.** Khi thiếu kiến thức, ghi `knowledge_gaps` (đã có, `KnowledgeContext.php:110`). Mỗi phiên bản ghi `knowledge_item_usages`. "Gợi ý cho kho" rộng hơn (số liệu chưa có trong kho, nguồn AI nêu mà sổ chưa có) do tài liệu Kho trí tuệ §5 định nghĩa; phía pipeline chỉ cam kết ghi đủ dữ liệu bước `research` để kho đọc được.
3. **`brain_build` là một loại việc trong registry**: ghi phiên chạy và bước (model, token, chi phí) cùng định dạng, tuân trần ngày của website. Khi có tìm web (Đ4), kết quả vào kho ở dạng đề xuất, **không** đi thẳng vào bài.
4. **Sản phẩm của công ty** (Đ6) đề xuất lưu như mục kiến thức trong kho, không thêm bảng sản phẩm. Cần chốt (Q5).

---

## 4. Một ví dụ đi hết chuỗi: bài "cách cải thiện tiểu đường"

Website bán thực phẩm chức năng, có Trí tuệ sức khỏe ở mức "bắt buộc nguồn". Cột "Hôm nay" nói thật mã hiện làm được gì.

| Bước | Loại việc | Ghi vào đâu | Hiện ở đâu | Người can thiệp | Hôm nay |
|---|---|---|---|---|---|
| 1 | `market_research` | `keywords`: "cải thiện tiểu đường", "chỉ số đường huyết bình thường"… với `source=ai`, `intent`; `keyword_clusters`: "Tiểu đường". Volume có nếu đã nhập CSV | Trang Từ khóa, bảng cụm | Chấp nhận cụm | Chỉ nhập tay/CSV; gom cụm minh họa |
| 2 | `content_plan` | `articles`: bài `planned`, `keyword_id`, `cluster_id`, `origin=plan`, `priority` | Trang Kế hoạch; tab "Từ khóa và kế hoạch" của bài | **Duyệt kế hoạch** | Người tự tạo bài từ cụm (thật) |
| 3 | `article_write`, dàn ý và nghiên cứu | Bước `brief`: dàn ý. Bước `research`: các mục kiến thức về đường huyết đã duyệt; sản phẩm liên quan của công ty; nguồn được phép trích (Bộ Y tế, WHO… **nếu người đã xác nhận trong Sổ nguồn**); ảnh; liên kết tới bài cùng cụm. Thiếu thì ghi `knowledge_gaps` | Tab "Nghiên cứu và nguồn" | Thấy thiếu kiến thức thì bổ sung kho | Có, nhưng lẫn trong bước viết; chưa có sản phẩm; chưa tìm web |
| 4 | `article_write`, viết | `article_versions` v1 kèm `sources_cited`; `knowledge_item_usages`; `article_version_media` | Tab "Nội dung": phiên bản, ảnh | — | Có. Ảnh chỉ chọn từ thư viện |
| 5 | `article_write`, chấm và sửa | Bước `grade`, `repair`; v2 nếu sửa. Nguồn ngoài sổ bị cờ | Tab "Nội dung": P0/P1/P2; tab "Hoạt động" | **Người có chuyên môn duyệt** (chủ đề y tế: luôn giữ) | Có. Kiểm nguồn mới ở mức cả bài |
| 6 | Xuất bản (không phải việc của đội AI ở T1) | `publications` | Tab "Xuất bản" | Xếp lịch | Có |
| 7 | `measure` | Số liệu theo bài; nếu tụt hạng: đề xuất làm mới | Mắt xích Đo lường trên trang bài | Chọn làm mới | Chưa có |
| 8 | `article_refresh` | `article_versions` v3, chỉ sửa mục có vấn đề | Tab "Nội dung": so sánh v2–v3 | Duyệt | Chạy được nhưng viết lại cả bài |

Điều ví dụ cho thấy: nguồn "tổ chức y tế, tài liệu được chứng nhận" chỉ vào bài qua **hai cửa có người gác**: Sổ nguồn đã xác nhận và mục kiến thức đã duyệt. Tìm web (khi có) chỉ nạp vào hai cửa đó.

---

## 5. Kiến trúc

### 5.1 Registry loại việc

Lớp mới `App\Services\Agent\Pipeline\JobTypeRegistry`, mỗi loại việc một file `resources/agents/job-types/<mã>.php` (cùng cách `FeatureRegistry` đang làm, `FeatureRegistry.php:19`). Mỗi file khai:

| Trường | Ý nghĩa |
|---|---|
| `id`, `title`, `link` | Mã, tên, và **mắt xích** nó phục vụ (1–6 hoặc `measure`) |
| `reads`, `writes` | Bảng/cột đọc và ghi. **Bắt buộc có `writes`.** |
| `shown_at`, `used_by` | Màn hiển thị kết quả; loại việc dùng tiếp. **Bắt buộc có cả hai.** |
| `steps[]` | Mỗi bước: `id` (≤ 20 ký tự), `kind` = `rule` hoặc `llm`; bước `llm` phải có `task` thuộc `AiModelRoute::TASKS`; `when` (điều kiện bỏ bước); `budget` |
| `budget`, `human`, `features`, `runner` | Trần cả phiên; điểm người can thiệp; tính năng liên quan trong `FeatureRegistry`; lớp thực thi |

Quy tắc: (1) `when` là **tên một điều kiện viết bằng PHP có test**, không phải biểu thức tự do, để giữ bộ điều phối tất định. (2) Bước bị bỏ vẫn ghi một dòng `skipped` kèm lý do (thói quen đã có, `AgentRunOrchestrator.php:186`). (3) Registry chỉ khai báo; mỗi loại việc có lớp chạy riêng. Chưa viết "máy chạy bước" tổng quát. (4) Test nhất quán làm hỏng bản dựng nếu một loại việc thiếu `writes`, `shown_at` hoặc `used_by`. Đây là cách biến quy tắc ở §3.1 thành luật trong mã.

### 5.2 Lưu phiên chạy

`market_research` có lời gọi AI nhưng không gắn một bài, nên hiện không lưu được vào `agent_runs` (§1.2).

| Phương án | Nội dung | Ưu | Nhược |
|---|---|---|---|
| **P1 — mở rộng `agent_runs`** *(khuyến nghị)* | Thêm `job_type` (mặc định `article_write`), cho `article_id` rỗng, thêm `parent_run_id`, `input_refs`, `output_ref` (JSON), `tokens_budget` | Trang Phiên chạy, Chi phí AI, hạn mức ngày dùng tiếp. Phiên cũ tự thành `article_write` | Đổi bảng lõi; phải rà mọi chỗ giả định phiên luôn có bài (chưa rà hết) |
| P2 — bảng mới `pipeline_runs` | Giữ nguyên `agent_runs` | Không đụng bảng đang chạy | Hai nơi lưu chi phí; sau này phải gộp |

Migration gốc tự ghi đây là "bản đề xuất cho Dev1 rà… Dev1 có thể đổi tên/cột" (`2026_11_07_000001:8-9`), nên P1 hợp ý đồ ban đầu nhưng **bắt buộc qua Dev1**. `site_audit` và `measure` 0 token và đã có bản ghi riêng (`seo_audits`), nên không cần chờ P1.

### 5.3 Nối các loại việc

Nối bằng **bản ghi đã lưu**, không gọi lồng nhau. Mỗi mũi tên dưới đây là một cột có thật ở §3.2.

```
 market_research ─► keywords, keyword_clusters ─► content_plan ─► articles (planned)
        ▲                                                             │
        │ keywords source=gsc                                         ▼
     measure ◄── publications ◄── (người duyệt, xếp lịch) ◄── article_write ◄── knowledge_items đã duyệt ◄── brain_build
        │                                                             │                                        ▲
        └─► đề xuất làm mới ─┐                                        └────────── knowledge_gaps ──────────────┘
 site_audit ─► audit_suggestions ─► articles (kind=refresh) ─► article_refresh ─► article_versions
```

Đầu vào quá cũ (đề xuất: audit 14 ngày, khảo sát 30 ngày, Q7) thì loại việc sau **hỏi**, không tự chạy lại loại trước.

### 5.4 Màn hình thay đổi

| Thành phần | Sau thay đổi |
|---|---|
| `PipelineBlueprint` | Giữ 9 giai đoạn làm bản đồ; mỗi giai đoạn trỏ tới loại việc phục vụ nó. Giữ nguyên luật "Đang hoạt động phải có bằng chứng" (`PipelineBlueprint.php:89`), áp theo từng loại việc. `controlsWired()` tính theo từng loại việc. |
| Tab Pipeline | Thẻ loại việc xếp theo chuỗi (§9.1) thay cho lưới 9 ô. |
| Trang bài (`ViewArticle`) và trang phiên (`RunFlow`) | Danh sách chặng lấy từ registry thay cho hai hằng số viết cứng. Tab "Nghiên cứu và nguồn" đọc bước `research`; với phiên cũ vẫn đọc bước `write` như hiện nay. |
| Trang Từ khóa, Kế hoạch, Audit | Thêm nút chạy loại việc tương ứng. Kết quả hiện ngay trên chính trang đó, không có màn kết quả riêng. |

### 5.5 Quan hệ với lõi

Không viết lại `AgentRunOrchestrator`. Nó là lớp chạy của `article_write`. Loại việc không viết bài có lớp chạy riêng, gọi AI theo mẫu an toàn của `KnowledgeAiIngest`. Bốn thay đổi nhỏ trong lõi được **đề xuất cho Dev1**, không tự làm: (a) tách bước `research` khỏi bước viết; (b) nhận dàn ý có sẵn để bỏ bước dàn ý; (c) kiểm ngân sách trước bước viết; (d) ghi `job_type`. Luật kiểm nguồn mới thuộc `ContentQualityChecker`/`QualityRubric`, cũng là lõi.

### 5.6 Tiết kiệm token

| Cơ chế | Ở đâu | Trạng thái |
|---|---|---|
| Chỉ chạy bước của loại việc được chọn; ba loại việc 0 token | Toàn bộ | Mới |
| Không có bước AI nào mà đầu ra không ai dùng | §3.1 | Mới |
| Bỏ bước có điều kiện (dàn ý đã có; từ đã có cụm; không còn lỗi chặn) | `article_write`, `market_research` | Có 1 điểm; mở rộng |
| Khảo sát một lần, nhiều bài dùng | `market_research` → `content_plan` | Mới |
| Model rẻ cho gom cụm, dàn ý, siêu dữ liệu; chỉ viết bài dùng bậc suy luận | Mọi bước `llm` | Hạ tầng đã có (`AiModelRoute`) |
| Gom lô (một lời gọi cho nhiều từ khóa) | `market_research` | Mới |
| Sửa một phần thay vì viết lại | `article_refresh` | Mới, cần lõi |
| Kiểm ngân sách trước khi gọi, theo từng bước | Mọi bước `llm` | Mới |
| Dừng sửa sớm khi không khá hơn | Vòng sửa | **Đã có** (`AgentRunOrchestrator.php:227`) |

---

## 6. Tự động hóa và điểm người can thiệp

**Mức tự chủ do bạn chọn**, cho từng website hoặc từng chiến dịch (cột `campaigns.mode` đã có ba giá trị). Hai lựa chọn chính, và bạn đổi được bất cứ lúc nào:

| Mức | Việc 0 token (`site_audit`, `measure`, `content_plan`) | Việc tốn token | Xuất bản | Tình trạng |
|---|---|---|---|---|
| **Duyệt trước** (Thận trọng, Cân bằng) | Thận trọng: người bấm. Cân bằng: tự chạy theo lịch | Người **duyệt kế hoạch trước**, sau đó đội AI viết trong ngân sách; người duyệt bản nháp | Người bấm đăng | Làm được khi có registry; phần "theo lịch" cần job nền (AG-B10) |
| **Toàn quyền cho đội AI** (Tự chủ) | Tự chạy | Tự nhận kế hoạch, tự viết, tự sửa, tự duyệt theo luật chấm | Tự xuất bản khi bậc xuất bản của website cho phép (T1 chỉ tới bản nháp) | **Đang khóa trong mã** (`CampaignRunner.php:53`); thiết kế bỏ khóa ở dưới |

Toàn quyền không có nghĩa là bỏ mọi chặn. Những chặn sau **giữ nguyên ở mọi mức**, vì chúng là điều kiện để AI chạy được một mình an toàn: trần ngân sách (mỗi lời gọi, bước, phiên, ngày); lỗi chặn P0 và từ cấm của hồ sơ doanh nghiệp (bài không qua thì không đăng); hạn mức bài mỗi ngày; nút dừng khẩn cấp; ghi nhật ký mọi việc đã làm. Riêng **cổng chuyên gia cho lĩnh vực rủi ro cao** là một công tắc: mặc định BẬT kể cả ở Toàn quyền; bạn tắt được, nhưng phải xác nhận rõ từng website và hệ thống ghi lại ai tắt, lúc nào. Rủi ro pháp lý của nội dung y tế đăng không qua người có chuyên môn thuộc về bạn.

**Không có điều kiện mở.** Người dùng chọn "Toàn quyền" được ngay, không cần số bài chạy thử hay ngưỡng. Lần đầu chọn, màn hình hiện tóm tắt `CHINH_SACH_TU_DONG_HOA.md` và một nút xác nhận; hệ thống ghi ai xác nhận, phiên bản chính sách, lúc nào. Đó là ghi nhận đã đọc, không chặn. Hai điều vẫn phải làm ở phía mã: bỏ khóa `CampaignRunner.php:53` (code của Dev1), và nói thẳng trên màn nếu connector của website chưa hỗ trợ gỡ bài, để người dùng biết sự cố phải gỡ tay (chính sách §6).

**Cổng duyệt**, xếp theo chuỗi:

| Cổng | Sau | Ai | Vì sao |
|---|---|---|---|
| Chấp nhận cụm và từ khóa AI đề xuất | `market_research` | Người | Sai ở đây kéo sai cả kế hoạch; sửa 0 token |
| **Duyệt kế hoạch** | `content_plan` | Người (Thận trọng, Cân bằng) | Điểm chặn rẻ nhất trước khi tiêu token viết |
| Bổ sung kiến thức thiếu | Bước nghiên cứu | Người, không chặn; quá 24 giờ thì đi tiếp và ghi giới hạn (cơ chế `CampaignWait` đã có) | Viết khi thiếu dữ kiện thì bài yếu |
| Duyệt bản nháp | `article_write`, `article_refresh` | Người | Bậc T1 |
| Chủ đề rủi ro cao / bắt buộc nguồn | Trong lúc viết | Người có chuyên môn; **không mức nào bỏ được** | Đã có (`AgentRunOrchestrator.php:249`) |
| Duyệt mục kiến thức | `brain_build` | Người | Kiến thức sai lan ra mọi bài |

**Dừng khi vượt ngân sách.** Bốn tầng, chạm tầng nào dừng ở tầng đó: mỗi lời gọi (**đã có**); mỗi bước (mới: không gọi nếu ước lượng vượt trần); mỗi phiên (có một phần; mở rộng ra kiểm trước bước viết); mỗi ngày theo chiến dịch (**đã có**, `CampaignRunner.php:62`) và theo website (mới, phụ thuộc AG-B09). Khi dừng, giữ kết quả đã có và nói rõ còn thiếu gì. Vì phiên chạy đồng bộ, hiện chỉ "dừng hẳn, giữ kết quả"; "tạm dừng rồi chạy tiếp" cần AG-B10.

---

## 7. Lộ trình

Mỗi giai đoạn vá một số chỗ đứt ở §1.3 và giao được độc lập. Việc không cần Dev1 đi trước.

| GĐ | Phạm vi | Vá | File chạm tới | Phụ thuộc Dev1 | Migration | Kiểm thử |
|---|---|---|---|---|---|---|
| **G0** | Nối từ khóa cho mọi bài: tạo tay và chiến dịch đều tìm-hoặc-tạo bản ghi `keywords`, ghi `keyword_id`, `origin`. Đo token thật 5–10 bài. | Đ1 | `CreateArticle.php`, `CampaignRunner.php`, dùng lại `PlannedArticleCreator` | Nên nhờ rà `CampaignRunner` | Không | Test: bài tạo tay có `keyword_id`; tab "Từ khóa và kế hoạch" hết cảnh báo. Bảng số đo thay ước lượng |
| **G1** | Registry + 7 file khai báo; tab Pipeline theo chuỗi; trang bài và trang phiên lấy chặng từ registry. Chưa loại việc mới nào chạy. | — | Mới: `JobTypeRegistry.php`, `resources/agents/job-types/*`. Sửa: `PipelineBlueprint.php`, `PipelineMap.php`, `RunFlow.php`, `ViewArticle.php` | Xác nhận thư mục mới | Không | Test nhất quán (§5.1); phiên cũ hiển thị không đổi |
| **G2** | Cột loại việc trên `agent_runs` (P1) | — | `AgentRun.php` và các màn đọc phiên | **Có** | `2026_11_10_100017_add_job_type_to_agent_runs`, **PR riêng** | Test lên/xuống; phiên không có bài hiển thị được |
| **G3** | `market_research` thật: AI đề xuất từ khóa và gom cụm, ghi `source=ai`, thay `MockClusterer`; đề xuất cụm lưu bền thay cho cache. `site_audit` bấm được từ giao diện với giới hạn số URL. | Đ2, Đ9 (một phần) | Mới: `Runners/MarketResearchRunner.php`, `Runners/SiteAuditRunner.php`. Sửa: `KeywordService.php`, `KeywordsPage.php`, `AuditPage.php`, `AiModelRoute::TASKS` | Không bắt buộc (cần G2 để ghi chi phí) | Có thể cần bảng đề xuất cụm, PR riêng | AI giả lập có nhãn: từ mới hiện ở trang Từ khóa với nhãn "AI đề xuất"; volume trống khi không có nguồn; chạy lại không tạo trùng |
| **G4** | Mắt xích 3 thành bước thật: bước `research`; sản phẩm liên quan; luật kiểm nguồn cho mọi bài (nguồn ngoài sổ và ngoài kho bị cờ); nhận dàn ý có sẵn; kiểm ngân sách trước bước viết. `content_plan` (luật). | Đ3, Đ5, Đ6 | **Lõi:** `AgentRunOrchestrator.php`, một luật mới trong `Quality/Rules`. Sửa: `ContentPlanPage.php`, `workspace.blade.php` | **Có** (lõi) | Không, nếu chốt Q5 theo khuyến nghị | Test: phiên có bước `research` trước `write`; nguồn bịa bị cờ; bộ test orchestrator cũ vẫn qua |
| **G5** | `article_refresh` sửa một phần | — (tiết kiệm) | Mới: `Runners/ArticleRefreshRunner.php`. **Lõi:** phương thức sửa một phần ở `AbstractLlmProvider` | **Có** | Không | Điểm sau sửa không giảm; so chi phí với số đo G0 |
| **G6** | Chạy nền và tự nối (AG-B10, AG-B09); `measure` (Task 5.1); đọc kết quả tìm web từ kho; tạo ảnh nếu được chọn | Đ4, Đ7, Đ8, Đ9 | `app/Jobs/*`, `routes/console.php`, phần Search Console | **Phụ thuộc hoàn toàn** | Bảng số liệu theo bài, PR riêng | Theo từng phần |

Trong worktree này `vendor/` trống nên không chạy được test tại đây. Test dùng AI giả lập phải giữ nhãn và không được dùng làm bằng chứng cho mức "Đang hoạt động".

---

## 8. Quyết định cần chốt và rủi ro

### 8.1 Quyết định

| # | Câu hỏi | Khuyến nghị | Ai chốt |
|---|---|---|---|
| Q1 | Bảy loại việc ở §3.2 và việc gộp chiến dịch vào viết bài có đúng ý bạn không? | Đồng ý | Bạn |
| Q2 | Lưu phiên chạy: mở rộng `agent_runs` hay bảng mới? | Mở rộng (P1) | Bạn + Dev1 |
| Q3 | Duyệt kế hoạch trước khi viết | **Đã chốt:** bắt buộc ở "Duyệt trước"; ở "Toàn quyền" thì không | Bạn |
| Q4 | Lượt tìm kiếm lấy từ đâu? AI không biết con số này. | Trước mắt CSV; sau đó Search Console; nguồn trả phí để sau | Bạn |
| Q5 | Sản phẩm của công ty lưu ở đâu để bài nhắc đúng? | Là mục kiến thức trong Kho trí tuệ (không thêm bảng) | Bạn + phiên Kho trí tuệ |
| Q6 | Có làm bước AI tạo ảnh không? | Chưa. Làm sau khi chuỗi chữ chạy thật; trước mắt chọn từ thư viện | Bạn |
| Q7 | Audit và khảo sát bao lâu thì coi là cũ? | 14 và 30 ngày | Bạn |
| Q8 | Nguồn AI nêu mà không có trong Sổ nguồn hay Kho: chặn hay chỉ gắn cờ? | Gắn cờ cho bài thường; giữ cho chuyên gia với lĩnh vực bắt buộc nguồn | Bạn |
| Q9 | Mức tự chủ | **Đã chốt:** bạn chọn giữa "Duyệt trước" và "Toàn quyền" cho từng website/chiến dịch. Mặc định website mới: Duyệt trước. Không đặt điều kiện mở Toàn quyền; có văn bản chính sách `CHINH_SACH_TU_DONG_HOA.md` hiện khi chọn lần đầu (§6) | Bạn |
| Q10 | Ngân sách từng loại việc và trần ngày mỗi website | Chốt sau khi có số đo G0 | Bạn |
| Q11 | Tách bước nghiên cứu trong lõi; thư mục `resources/agents/job-types/` | Tách, đặt sau dàn ý; xác nhận thư mục | Dev1 |

### 8.2 Rủi ro

| Rủi ro | Giảm thiểu |
|---|---|
| Ước lượng token sai | G0 đo trước; không cam kết con số |
| AI đề xuất từ khóa nghe hợp lý nhưng không ai tìm | Luôn hiện "chưa có số liệu tìm kiếm"; xếp ưu tiên thấp hơn từ có số liệu |
| Đổi `article_id` thành cho rỗng làm vỡ màn đang giả định phiên có bài | PR riêng, có test; phương án lùi P2 |
| Phụ thuộc Dev1 (AG-B10, AG-B09, review lõi) làm G4–G6 trượt | G0, G1, G3 không cần lõi; bốn thay đổi lõi gói nhỏ, mô tả ở §5.5 |
| Chạy đồng bộ: audit hay khảo sát lớn làm treo trang | Giới hạn số URL, số từ khóa mỗi lần cho tới G6 |
| Nhiều lớp chạy lặp mã (ghi bước, thử lại, gọi AI) | Tách phần chung khỏi orchestrator với Dev1; trước đó chấp nhận lặp có kiểm soát |
| Luật kiểm nguồn gắn cờ quá nhiều, người duyệt bỏ qua cờ | Bắt đầu ở mức P1 (không chặn); đo tỷ lệ cờ trước khi nâng mức |

---

## 9. Wireframe

Phác thảo bố cục; số liệu là ví dụ.

### 9.1 Chọn loại việc (tab Pipeline), xếp theo chuỗi

```
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Đội AI ▸ Pipeline                 Website: [ addp.vn ▾ ]     Hôm nay: 62.000/150.000 ₫   │
│ GỢI Ý: 14 từ khóa trong cụm "Tiểu đường" chưa có bài.                 [ Lập kế hoạch ]   │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ 1 TỪ KHÓA              2 KẾ HOẠCH             3–5 NGHIÊN CỨU · VIẾT · KIỂM               │
│ ┌────────────────────┐ ┌────────────────────┐ ┌────────────────────┐ ┌──────────────────┐│
│ │ Khảo sát thị trường│ │ Lập kế hoạch       │ │ Viết bài           │ │ Làm mới bài      ││
│ │ Ghi: từ khóa, cụm  │ │ Ghi: bài chờ viết  │ │ Ghi: phiên bản,    │ │ Ghi: phiên bản   ││
│ │ Hiện: trang Từ khóa│ │ Hiện: trang Kế hoạch│ │ nguồn, ảnh         │ │ mới của bài      ││
│ │ AI rẻ              │ │ Không tốn token    │ │ Hiện: trang bài    │ │ 4 đề xuất chờ    ││
│ │ [ Chạy ]           │ │ [ Chạy ]           │ │ [ Chọn bài ]       │ │ [ Chọn bài ]     ││
│ └────────────────────┘ └────────────────────┘ └────────────────────┘ └──────────────────┘│
│ TÍN HIỆU ĐỂ LÀM MỚI                           NGUỒN CHO BƯỚC NGHIÊN CỨU                  │
│ ┌────────────────────┐ ┌────────────────────┐ ┌────────────────────┐                     │
│ │ Audit website      │ │ Đo lường           │ │ Kho trí tuệ        │                     │
│ │ Không tốn token    │ │ ○ Chưa kết nối     │ │ 3 khoảng trống     │                     │
│ │ [ Quét ]           │ │ [ Vì sao? ]        │ │ [ Mở kho ]         │                     │
│ └────────────────────┘ └────────────────────┘ └────────────────────┘                     │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

Khi bấm "Chạy", một cửa sổ trượt liệt kê các bước, bước nào tốn token, bước nào sẽ bị bỏ và vì sao, ước lượng chi phí, và **kết quả sẽ hiện ở màn nào**.

### 9.2 Theo dõi một phiên (tab "Hoạt động" của trang bài)

```
┌───────────────────────────────────────────────────────────────────────────────────────┐
│ Phiên #214 · Viết bài · "Cách cải thiện tiểu đường"      ● Đang chạy · AI thật        │
│ Ngân sách: ▓▓▓░░░░░░░ 14.300 / 50.000 ₫                              [ Dừng phiên ]   │
├──────────────────────────────┬────────────────────────────────────────────────────────┤
│ ✔ 1 Kiểm điều kiện     luật  │ BƯỚC 4 · Nghiên cứu (không tốn token)                  │
│ ✔ 2 Xét rủi ro         luật  │ Kiến thức đã duyệt: 6 mục (Trí tuệ "Sức khỏe")         │
│     → Cao: cần chuyên gia    │ Sản phẩm liên quan: 2                                  │
│ ✔ 3 Lập dàn ý       AI · rẻ  │ Nguồn được phép trích: 4 (Bộ Y tế, WHO, …)             │
│ ✔ 4 Nghiên cứu         luật  │ Ảnh chọn từ thư viện: 2 · Liên kết nội bộ: 3           │
│ ◉ 5 Viết      AI · suy luận  │ ⚠ Thiếu: "chỉ số HbA1c mục tiêu" → đã ghi vào          │
│ ○ 6 Chấm điểm          luật  │   khoảng trống kiến thức        [ Bổ sung vào kho ]    │
│ ○ 7 Sửa (nếu cần)  AI · chuẩn│                                                        │
│ ○ 8 Bàn giao           luật  │ Xem đầy đủ ở tab "Nghiên cứu và nguồn".                │
├──────────────────────────────┴────────────────────────────────────────────────────────┤
│ CẦN BẠN: khi xong, bài vào hàng chờ người có chuyên môn duyệt (không tự xuất bản).    │
└───────────────────────────────────────────────────────────────────────────────────────┘
```

Hiệu ứng "đang chạy" theo từng bước chỉ chính xác khi phiên chạy nền (G6). Khi vượt ngân sách, dòng trạng thái ghi: đã chi bao nhiêu, bước nào **không** được gọi, bản nháp nào được giữ, và ba lựa chọn: xem bản nháp, sửa tay rồi chấm lại (0 ₫), cấp thêm ngân sách.

---

## Phụ lục — Chưa kiểm hoặc chưa chắc

- Chưa đo token thật; mọi con số token và chi phí là ước lượng hoặc ví dụ.
- Chưa rà hết các chỗ giả định `agent_runs.article_id` luôn có giá trị.
- Chưa kiểm trang Kế hoạch nội dung ghi `articles.priority` và `origin=plan` ở những đường nào; mới xác nhận `PlannedArticleCreator` ghi `origin`, `keyword_id`, `cluster_id` và `AuditSuggestionService` ghi `priority`.
- Chưa kiểm mã nào ghi `keywords.source` = `gsc` hoặc `crawl`; mới xác nhận `csv`, `tay`, `competitor` có nơi ghi và `ai` thì không.
- Chưa kiểm nguồn kiến thức loại `url` trong kho có tải trang thật hay chỉ lưu đường dẫn.
- Chưa đọc `app/Services/Pilot` và `resources/prompts` (mã của Dev1). G4, G5 có thể cần lời nhắc mới do Dev1 thêm.
- "Không có mã tìm web", "không có mã tạo ảnh", "không có bảng sản phẩm" dựa trên tìm kiếm theo từ khóa trong `app/` và danh sách migration, không phải đọc từng file.
- Điểm ghép ở §3.4 viết theo mục lục của `THIET_KE_KHO_TRI_TUE_TU_DONG.md` (§5 "Gợi ý cho kho"); chưa đối chiếu từng chi tiết vì tài liệu đó đang được phiên khác sửa.
- Chưa đọc `KHO_TRI_TUE_DESIGN.md`, `DATA_BACKBONE_DESIGN.md`, `APPLY_PLAN_V2/V3`. `HANDOVER_2026-11-10.md` đọc từ checkout chính, không có trong worktree này.
