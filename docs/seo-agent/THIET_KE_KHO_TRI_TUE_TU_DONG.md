# Thiết kế: Kho trí tuệ tự động, gắn vào chuỗi làm ra một bài

Ngày viết: 10/11/2026 (bản 2, sửa theo chuỗi 6 mắt xích của trang bài viết, commit `c9191e0`). Trạng thái: **bản thiết kế, chưa có mã**. Người đọc: người làm SEO, marketing, nội dung và lập trình viên.

Câu hỏi tổ chức tài liệu: **một bài cần gì từ kho, và kho học lại gì từ bài.** Chuỗi làm bài: (1) Từ khóa → (2) Kế hoạch → (3) Nghiên cứu và nguồn → (4) Viết → (5) Kiểm và duyệt → (6) Xuất bản, rồi đo lường. Kho trí tuệ phục vụ mắt xích 3. Tính năng nào không chỉ ra được nó giúp mắt xích nào thì bị cắt hoặc để sau (phần 8).

Tài liệu liên quan: `THIET_KE_PIPELINE_THEO_LOAI_VIEC.md` (loại việc `brain_build`, chi tiết pipeline do bản đó lo). Ở bản checkout chính (`addp-autoseo/docs/seo-agent/`, không có trong nhánh này): `KHO_TRI_TUE_RESEARCH_VA_KIEN_TRUC.md`, `KHO_TRI_TUE_DESIGN.md`, `KNOWLEDGE_BASE_DESIGN.md`, `reports/KHO_TRI_TUE_P2_KE_HOACH.md`.

Hai điểm **khác quy tắc cũ**, chỉ làm khi chủ dự án đồng ý rõ: `KNOWLEDGE_BASE_DESIGN.md` §3 ghi "Không làm: tự duyệt, tự tìm web ngoài (AGENTS.md §1.3)". Tài liệu này đề nghị cho tự nhận một nhóm mục rủi ro thấp (Q2) và tìm web có kiểm soát (Q4).

Quy ước: đường dẫn tính từ gốc kho mã; số dòng đúng lúc đọc (10/11/2026). **(suy đoán)** là nhận định chưa kiểm bằng mã hoặc chạy thật. Mức bằng chứng của phần đã có: 1–2 trên 4 (có mã, test với AI giả). Chưa AI thật, chưa staging, chưa người dùng thật.

---

## 1. Hiện trạng (đã đối chiếu mã)

### 1.1. Hôm nay một bài lấy gì từ kho

| Thứ bài nhận | Từ đâu | Mã |
|---|---|---|
| Tối đa 5 mục kiến thức **đã duyệt**, mỗi mục cắt còn 600 ký tự | `knowledge_items`, tìm theo từ khóa chính + 12 dòng dàn ý đầu | `KnowledgeContext.php:18-20`, `load()` `:46`; `KnowledgeRetriever::search` `:24` |
| Quy tắc "quan niệm sai" và "không được nói" | Mục loại `myth`, `forbidden` | `KnowledgeRetriever::rules`; `KnowledgeItem::RULE_TYPES` |
| Khung lĩnh vực (giọng, thuật ngữ, lập luận) | `brain_profiles` | `BrainContext::options/domain` `:134-142` |
| Giọng thương hiệu, thông điệp, từ cấm | `brand_profiles` | `AgentRunOrchestrator::brandOptions` `:379` |
| Danh sách nguồn được phép trích, **chỉ khi** Trí tuệ đặt `must_cite` | `brain_sources` đã xác nhận | `AgentRunOrchestrator.php:295-297`; `BrainSourceRegistry::citable` `:44` |

Chuỗi chạy hiện có các bước `guard | risk | brief | write | grade | repair | handoff` (`2026_11_10_100001_create_agent_runs_tables.php:43`). **Không có bước nghiên cứu riêng**: kho được nạp ngay trong bước viết (`AgentRunOrchestrator::write` `:282`).

Tab "Nghiên cứu và nguồn" của bài (`workspace.blade.php:76`, khối `:266-346`; dữ liệu ở `ViewArticle.php:162-186`) hiện bốn thẻ: Từ Kho trí tuệ, Từ hồ sơ doanh nghiệp, Nguồn AI nêu trong bài, Dàn ý AI lập. Kèm cảnh báo "Kho thiếu kiến thức" và dòng "Hệ thống hiện chưa tự tìm nguồn trên web hay từ tổ chức chuyên ngành" (`:344`).

Ba điều cần hiểu đúng:
- "Kiến thức đã dùng" thật ra là **mục đã đưa vào lời nhắc**, không chắc AI đã dùng (`KnowledgeContext::recordUsage` `:93`).
- "Nguồn AI nêu" là danh sách **chuỗi chữ tự do** do model trả về (`AbstractLlmProvider.php:81,109`), lưu ở `article_versions.sources_cited`. Không trỏ về bản ghi nào, không có URL bắt buộc, chưa ai kiểm.
- Kiểm bằng chứng `EVID01` chỉ chạy khi `must_cite`, ở mức cả bài, không nối từng câu với từng nguồn (`EvidenceRule.php`).

### 1.2. Hôm nay kho học gì từ bài

Chỉ một thứ: **khoảng trống**. Khi tìm ra **không** mục nào, hệ thống ghi một dòng `knowledge_gaps` với từ khóa chính (`KnowledgeContext.php:62-65`, `recordGap` `:105`) và đặt `agent_run_steps.data.knowledge.gap = true` (`stepInfo` `:79-82`). Có 1–2 mục nhưng thiếu ý chính thì không ghi gì. Khi một mục khớp được duyệt, khoảng trống tự đóng (`KnowledgeGaps::fillFrom` `:34`).

Kết quả kiểm chất lượng **không được lưu đầy đủ**: bước `grade` chỉ lưu tối đa 8 luật trượt gồm mã, mức, thông báo (`AgentRunOrchestrator::grade` `:328-337`); trang duyệt tính lại khi mở (`ReviewActions::qualityFor` `:58`).

### 1.3. Việc người đang làm tay

| Việc | Mã |
|---|---|
| Tạo Trí tuệ (tên, mẫu, website); hồ sơ chỉ có khung, phải tự điền | `BrainLibraryPage::createBrain` `:134` |
| Điền Hồ sơ lĩnh vực (11 trường) và Hồ sơ doanh nghiệp | `BrainProfile::FIELDS` `:17`; `BrandProfile` `:21`; `BrainPage::saveProfile` `:200` |
| Dán tài liệu (chỉ nhận **văn bản dán**), rồi bấm tách từng tài liệu | `KnowledgePage::pasteDoc` `:238`, `splitSource` `:159`, `splitSourceAi` `:168` |
| Thêm mục bằng tay, tạo chủ đề | `KnowledgePage::addItem` `:229`, `addTopic` `:252` |
| Duyệt **mọi** mục AI đề xuất; xử lý trùng, mâu thuẫn | `KnowledgePage::approve` `:127`; `KnowledgeReview` |
| Thêm nguồn vào Sổ nguồn, xếp bậc, xác nhận | `BrainPage::addSource` `:237`, `setSourceTier` `:254` |
| Lấp khoảng trống bằng cách tự viết mục | `KnowledgePage::prefillFromGap` `:185` |

Trang kho chung có 6 tab; trang một Trí tuệ có 9 tab, Trí tuệ Thương hiệu có 10 (`knowledge.blade.php:20-36`). *(Bản 1 ghi "mười tab" cho mọi trang là chưa chính xác.)*

### 1.4. AI đã làm được gì cho kho

Tách tài liệu **đã dán** thành mục đề xuất (`KnowledgeAiIngest::run` `:171`): đoạn 3.000 ký tự (`:28`), tối đa 12 đoạn (`:43`), 60 mục (`:48`), trần 5.000 đồng mỗi tài liệu (`:54`). Mỗi mục phải có đoạn nguyên văn và con số có thật trong đoạn gốc (`validate` `:264`). Trạng thái luôn `proposed`, không tự duyệt (`store` `:350`, `:366`). AI không có công cụ, không truy cập web (`systemPrompt` `:153`). Trùng và mâu thuẫn do quy tắc cố định phát hiện (`KnowledgeDedupe`, `KnowledgeConflicts`).

### 1.5. Chưa tồn tại

1. **Tìm web.** Không có khai báo công cụ hay tìm kiếm nào trong `app/Services/Ai`, `app/Contracts`.
2. **Tải trang từ URL để nạp kiến thức.** `KnowledgeIngest::paste` (`:24`) có tham số `kind`, `url` nhưng không mã nào tải.
3. **Nội dung trang của chính website.** `crawled_urls` chỉ lưu tiêu đề, mô tả, H1, tóm tắt đề mục, số đếm, điểm, lỗi (`2026_11_06_000002_…:15-34`). Không có phần chữ. Muốn đọc website thì **phải tải lại trang**.
4. **Dữ liệu sản phẩm.** Không có bảng hay trường sản phẩm nào. `BrandProfile` không có trường sản phẩm. `MagentoBridgeClient` chỉ có năm hàm phục vụ đăng bài (`testConnection`, `uploadMedia`, `createDraft`, `publish`, `getByPublicationKey`), không đọc danh mục.
5. **Nguồn trích trỏ về bản ghi có URL.** Xem 1.1.
6. **Việc chạy nền.** Chỉ có lịch `agent:tick` 15 phút (`routes/console.php`); hàng đợi là việc AG-B10 của Dev1.

---

## 2. Đánh giá thẳng

**Chưa tiện, và chưa thành một thể với bài viết.** Phần quản trị (duyệt, phiên bản, hạn hiệu lực, mâu thuẫn, điều cấm) làm cẩn thận và nên giữ. Hai chỗ hỏng:

- **Đầu vào toàn bằng tay.** Kho trống cho tới khi có người dán tài liệu. Bài đầu tiên của website mới gần như không được kho hỗ trợ, và tab Nghiên cứu chỉ báo "kho thiếu".
- **Vòng lặp bài ↔ kho bị hở.** Bài báo thiếu, nhưng không có gì tự đi tìm. Nguồn AI nêu không kiểm được. Người duyệt bài y tế không có cách bấm vào nguồn để đối chiếu.

Theo vai trò: người SEO không biết kho đã phủ từ khóa nào; người marketing phải gõ lại điều đã có trên website; người viết không có đường đưa thông tin đúng từ bài vào kho; người duyệt nhận mọi mục với ưu tiên như nhau, số điện thoại cửa hàng xếp cùng hàng với liều thuốc.

So với kiểu NotebookLM: thiếu "thả nguồn vào là tự đọc" và "bấm trích dẫn thấy đoạn gốc". Thừa hơn ở chỗ kiến thức được dùng để viết bài và được duyệt, phiên bản hóa. *(So sánh ở mức kiểu trải nghiệm, không đối chiếu tính năng hiện hành.)*

---

## 3. Nguyên tắc

1. **AI xây và cập nhật kho; người chỉ xử lý ngoại lệ.**
2. **Cấu hình tối thiểu:** chọn website và viết vài câu. Phần còn lại có mặc định.
3. **Mọi mục truy được về nguồn:** trang nào, tải lúc nào, đoạn nguyên văn nào. Không có đoạn khớp thì không tạo mục (đã có, giữ nguyên).
4. **Nội dung từ web và tài liệu là dữ liệu, không phải chỉ dẫn** (giữ cách đóng khung của `KnowledgeAiIngest`).
5. **Hệ thống không tự khẳng định nguồn nào uy tín** (`BrainSourceRegistry.php:13-17`). Danh sách tên miền tin cậy theo lĩnh vực do người chốt trong Sổ nguồn.
6. **Không đẻ thêm thứ không giúp bài tốt hơn.** Ưu tiên dùng lại bảng và màn hình đang có.

### 3.1. Tự động mà không tự phong "uy tín": phân tầng theo rủi ro của nội dung

| Tầng | Nội dung | Xử lý mặc định |
|---|---|---|
| **A** | Thông tin của chính doanh nghiệp, lấy từ chính website của họ; loại `fact`, `process`, `faq`, `messaging`; không có giá; Trí tuệ không thuộc rủi ro cao | **Tự nhận**, gắn nhãn "tự nhận", hoàn tác được |
| **B** | Kiến thức chung, nguồn bên ngoài | **Chờ duyệt**, kèm tín hiệu tin cậy |
| **C** | Giá, chính sách; mọi con số y khoa, pháp lý, tài chính; loại `myth`, `forbidden`; mọi mục của Trí tuệ có `risk_class = high` hoặc `ai_reliance = must_cite` | **Luôn chờ người duyệt** |

Tầng C xác định bằng **quy tắc cố định trong mã** (loại mục, có con số kèm đơn vị tiền, mức rủi ro của Trí tuệ), không do AI quyết và không cấu hình được.

Thay cho nhãn "uy tín", mỗi mục chờ duyệt hiện các **dữ kiện quan sát được**: tên miền; là website của bạn, có trong Sổ nguồn đã xác nhận (bậc mấy), hay ngoài sổ; ngày tải; đoạn nguyên văn; có mâu thuẫn với mục đã duyệt không. Không cộng thành một điểm số.

---

## 4. Một bài cần gì từ kho: bốn loại nguồn

Mỗi loại trả lời năm câu: lấy từ đâu, ghi vào đâu, hiện ở đâu, bài dùng thế nào, người can thiệp chỗ nào.

### 4.1. Kiến thức đã có trong kho

| | |
|---|---|
| Lấy từ đâu | `knowledge_items` đã duyệt, còn hạn, thuộc các Trí tuệ của bài (`BrainContext::forArticle`). **Đã có.** |
| Ghi vào đâu | `knowledge_item_usages` (mục nào, phiên bản nào, cho bản bài nào). **Đã có.** |
| Hiện ở trang kho | Tab Hiểu biết. Thêm: mỗi mục ghi "đã đưa vào N bài" (đếm từ `knowledge_item_usages`, không cần bảng mới). |
| Hiện ở tab Nghiên cứu | Thẻ "Từ Kho trí tuệ" đang có. Thêm: tên miền và đường dẫn tài liệu gốc bấm được, ngày tải, đoạn nguyên văn khi rê chuột. Đổi nhãn thành "Kiến thức đã đưa cho AI" cho đúng nghĩa. |
| Bài dùng thế nào | Đưa vào lời nhắc bước viết như hiện nay. |
| Người can thiệp | Không, trừ khi mục hết hạn hoặc có mâu thuẫn. |

Điểm yếu cần sửa: khoảng trống chỉ ghi khi tìm ra không mục nào. Đề nghị ghi thêm khi tìm được ít hơn 2 mục **(ngưỡng là đề xuất, cần đo)**.

### 4.2. Website và sản phẩm của công ty

**Nói thẳng: hiện không có dữ liệu sản phẩm và không có phần chữ của trang nào** (1.5 mục 3, 4).

| | |
|---|---|
| Lấy từ đâu | Trang của chính website: danh sách URL từ sitemap (`SitemapCrawlerService::discoverUrls` `:47`), **tải lại** qua bộ tải an toàn, chỉ cho tên miền của website. Ưu tiên theo đường dẫn: giới thiệu, dịch vụ, **sản phẩm**, chính sách, hỏi đáp, liên hệ. |
| Ghi vào đâu | `knowledge_sources` (`kind = 'url'`, `url`, `raw_text`, `content_hash`: các cột đã có) → `knowledge_items` qua `KnowledgeAiIngest`. |
| Sản phẩm, cách tối thiểu | **Không dựng danh mục sản phẩm, không nối Magento.** Mỗi sản phẩm là mục kiến thức loại mới `product`: `entity` = tên sản phẩm, `body` = mô tả ngắn và công dụng **đúng như trang ghi**, nguồn = trang sản phẩm. Cột `type` là `string(12)` nên không cần migration, giống cách đã thêm `myth`/`forbidden`. Website không có trang sản phẩm nhận ra được thì người dùng dán danh sách URL sản phẩm. |
| Hiện ở trang kho | Tab Nguồn: các trang đã đọc, ngày tải, số mục rút ra. Tab Hiểu biết: lọc theo loại Sản phẩm. |
| Hiện ở tab Nghiên cứu | Thẻ mới "Sản phẩm liên quan của công ty": tên, đường dẫn trang sản phẩm, trạng thái duyệt. Thẻ hồ sơ doanh nghiệp giữ nguyên. |
| Bài dùng thế nào | Bước nghiên cứu dành riêng tối đa 2 chỗ cho mục `product` khớp chủ đề, ngoài 5 chỗ kiến thức. Bước viết được dặn: chỉ nhắc sản phẩm trong danh sách, chỉ nói công dụng có trong mục và trong "điều được phép khẳng định" của hồ sơ doanh nghiệp. Liên kết về trang sản phẩm dùng đúng URL nguồn. |
| Người can thiệp | Thông tin doanh nghiệp thông thường: tầng A, tự nhận. **Giá và chính sách: luôn duyệt.** Sản phẩm thuộc lĩnh vực rủi ro cao (thực phẩm chức năng, thiết bị y tế): **luôn duyệt**, vì công dụng sản phẩm sức khỏe là nội dung quảng cáo có ràng buộc pháp lý. |

Để sau: đồng bộ danh mục từ Magento hoặc CMS. Chưa kiểm phía Magento có sẵn API đọc sản phẩm hay không.

Cũng từ lượt đọc website: AI điền **nháp** Hồ sơ doanh nghiệp (lĩnh vực, khách hàng, thông điệp chính). Người lưu mới có hiệu lực. Lý do giữ: tab Nghiên cứu đang báo "website chưa có hồ sơ doanh nghiệp nên AI viết mà không có giọng thương hiệu".

### 4.3. Tổ chức chuyên ngành và tài liệu được chứng nhận

| | |
|---|---|
| Lấy từ đâu | Chỉ từ tên miền **đã được người xác nhận và xếp bậc** trong Sổ nguồn (`brain_sources.status = confirmed`). Mẫu lĩnh vực (ví dụ sức khỏe) gợi ý sẵn danh sách, nhưng gợi ý chưa xác nhận không được tính (`BrainSourceRegistry::importSuggested` `:163`, `BrainSource::isEffective`). **Đã có sổ; chưa có việc tải.** |
| Hai đường đưa tài liệu vào | (a) **Người dán URL** thuộc tên miền đã xác nhận, hệ thống tải và tách. Không cần nhà cung cấp tìm kiếm. (b) Sau này: hệ thống **tự tìm trong phạm vi các tên miền đã xác nhận** rồi tự kiểm lại tên miền ở phía mình (`BrainSourceRegistry::hostOf`, `classify`). |
| Ghi vào đâu | `knowledge_sources` (thêm cột nguồn gốc và ngày tải, phần 9) → `knowledge_items`, tầng B hoặc C. Tài liệu được chứng nhận dạng tệp: vẫn theo đường dán văn bản hiện có; đọc PDF để sau. |
| Hiện ở trang kho | Tab Sổ nguồn: mỗi tên miền ghi số tài liệu đã đọc, số mục đã duyệt. Tab Nguồn: tài liệu kèm bậc của tên miền. |
| Hiện ở tab Nghiên cứu | Thẻ "Nguồn chuyên ngành": tên tổ chức, bậc do người xếp, đường dẫn, ngày tải, trạng thái mục. |
| Bài dùng thế nào | Mục đã duyệt vào lời nhắc. Với `must_cite`, bài chỉ được trích nguồn trong sổ (đã có) và `EVID01` chấm đủ hay thiếu bằng chứng (đã có). |
| Người can thiệp | **Chốt danh sách tên miền và bậc** (một lần cho mỗi lĩnh vực, sửa khi cần). **Duyệt mọi mục** rút ra khi Trí tuệ rủi ro cao. |

### 4.4. Web nói chung

| | |
|---|---|
| Lấy từ đâu | Nhà cung cấp tìm kiếm (phần 10). **Chưa có gì. Làm sau cùng.** |
| Ghi vào đâu | Trang tìm thấy → `knowledge_sources` với nguồn gốc `search`. Tên miền mới → `brain_sources` trạng thái `suggested`, **không có bậc, không được tính**. Mục rút ra: tầng B hoặc C, luôn chờ duyệt. |
| Hiện ở trang kho | Hộp duyệt, kèm dữ kiện "ngoài Sổ nguồn". Tab Sổ nguồn: tên miền gợi ý chờ người quyết. |
| Hiện ở tab Nghiên cứu | Thẻ "Từ web (chưa xác nhận)": đường dẫn, ngày tải, nhãn "ngoài Sổ nguồn". |
| Bài dùng thế nào | Chỉ dùng sau khi mục được duyệt. Với Trí tuệ `must_cite`: nguồn ngoài sổ **không được tính là bằng chứng**; chỉ giúp gợi ý câu hỏi và góc viết. |
| Người can thiệp | Duyệt mục; quyết có đưa tên miền vào sổ không. |

### 4.5. Bước nghiên cứu của bài gọi kho thế nào (điểm ghép với bản pipeline)

Thêm bước `research` vào loại việc viết bài (`article_single`, `article_campaign`, `article_refresh`), đặt sau `brief`, trước `write`. Cột `stage` là chuỗi nên không cần migration, nhưng sửa `AgentRunOrchestrator` là **mã lõi, cần Dev1**.

1. Tìm kho theo từ khóa và dàn ý (việc `KnowledgeContext::load` đang làm, chuyển lên bước này).
2. Đủ thì đi tiếp. Thiếu thì gọi loại việc **`brain_build`** với phạm vi là khoảng trống của bài; `brain_build` đi tìm theo thứ tự: website của công ty → tên miền đã xác nhận → web (khi đã bật).
3. Ghi kết quả vào `agent_run_steps.data` của bước `research`: mục đã chọn, khoảng trống, tài liệu vừa đọc, số mục chờ duyệt, chi phí. Tab Nghiên cứu đọc từ đây, giống cách đang đọc dữ liệu bước `write`.

`brain_build` cũng chạy độc lập: lượt xây đầu khi tạo Trí tuệ, và làm mới. Hợp đồng ghi phiên, trần chi phí, đăng ký loại việc theo `THIET_KE_PIPELINE_THEO_LOAI_VIEC.md` §3.2-G. Bản đó ghi "duyệt mục kiến thức là bắt buộc"; nếu Q2 được chấp nhận thì câu đó cần sửa thành "bắt buộc trừ tầng A".

---

## 5. Kho học gì từ bài: khi kho thiếu

### 5.1. Dòng chảy

```
 bước research của bài: kho thiếu (knowledge.gap, knowledge_gaps)
        │
        ▼
 brain_build (phạm vi: khoảng trống này)
   website công ty → tên miền đã xác nhận → web (khi bật)
        │ tải an toàn, tách, kiểm đoạn nguyên văn
        ▼
 knowledge_sources (có URL, ngày tải)  +  knowledge_items
        │
        ├─ tầng A ─► tự nhận ─► bài dùng ngay trong lượt này
        └─ tầng B, C ─► chờ duyệt ─► KHÔNG vào bài lượt này
                              │
                              ▼
        tab Nghiên cứu: "N mục chờ duyệt có thể giúp bài này  [Duyệt] [Viết lại]"
```

### 5.2. Mục và nguồn mới vào kho ở trạng thái gì

- Tài liệu: `knowledge_sources`, ghi nguồn gốc (`own_site` / `registry` / `search` / `user`), ngày tải, URL cuối cùng.
- Mục: `proposed`, trừ tầng A được tự nhận. **Mục chưa duyệt không bao giờ vào bài** (giữ hợp đồng của bản pipeline).
- Tên miền mới: `brain_sources` trạng thái `suggested`, không bậc.
- Khoảng trống: giữ `open` cho tới khi một mục khớp được duyệt (cơ chế đã có).

### 5.3. Bài chờ hay viết tiếp khi còn mục chờ duyệt

- Trí tuệ thường: **viết tiếp** bằng mục đã có, giữ cảnh báo thiếu, hiện nút "Duyệt rồi viết lại".
- Trí tuệ rủi ro cao hoặc `must_cite`: **dừng trước bước viết**, chờ người có chuyên môn duyệt kiến thức. Viết bài y tế khi chưa có bằng chứng rồi sửa lại là tốn hai lần chi phí và dễ lọt câu sai. Đây là đề nghị, cần chốt (Q3).

### 5.4. Trích nguồn: thay "nguồn AI tự nêu"

Mục tiêu: mỗi nguồn hiện trong bài **trỏ về một `knowledge_sources` có URL**, kèm trạng thái kiểm.

Cách làm, hai bước:

1. **Không cần đổi lời nhắc, không cần bảng mới.** Nguồn của bài được **suy ra bằng quy tắc** từ `knowledge_item_usages` → `knowledge_items` → `knowledge_sources`. Mỗi chuỗi trong `sources_cited` được đối chiếu với Sổ nguồn bằng `BrainSourceRegistry::classify` (đã có, `EVID01` đang dùng). Cái nào không khớp thì giữ nhãn "AI tự nêu, chưa kiểm".
2. **Cần Dev1 (lời nhắc lõi).** Bước viết nhận danh sách nguồn của các mục đã nạp (mã, tên, URL) và chỉ được ghi `sources_cited` trong danh sách đó, như cách đang làm với `citable_sources` cho `must_cite`. Nguồn ngoài danh sách bị đánh dấu.

"Trạng thái kiểm" là **bốn dữ kiện tách rời**, không phải lời bảo đảm nội dung đúng:

| Dữ kiện | Nghĩa |
|---|---|
| Tải được | Hệ thống đã tải URL này, ngày nào |
| Khớp đoạn gốc | Mục kiến thức có đoạn nguyên văn nằm trong trang |
| Trong Sổ nguồn | Tên miền được người xác nhận, bậc mấy; hoặc "ngoài sổ"; hoặc "bị chặn" |
| Người đã duyệt | Ai duyệt mục, lúc nào; hoặc "tự nhận" |

Giới hạn nói trước: vẫn ở **mức cả bài**. Chưa biết câu nào trong bài dựa vào nguồn nào.

### 5.5. Dữ liệu cần thêm để tab Nghiên cứu hiện được

| Cần | Hiện có chưa | Thêm gì |
|---|---|---|
| URL và ngày tải của tài liệu gốc | Cột `url` có; chưa mã nào điền từ việc tải | `knowledge_sources`: `origin`, `fetched_at`, `final_url`, `http_status` |
| Đoạn nguyên văn của mục | Có (`knowledge_items.source_span`) | Không |
| Bậc và trạng thái tên miền | Có (`brain_sources`) | Không |
| Mục tự nhận hay người duyệt | Chưa | `knowledge_items`: `risk_tier`, `review_mode` |
| Bước nghiên cứu đã tìm gì, còn chờ gì | Chưa | Không cần bảng: `agent_run_steps.data` của bước `research` |
| Sản phẩm liên quan | Chưa | Không cần bảng: loại mục `product` |

Ảnh chụp trạng thái nguồn tại lúc viết (để sau này sổ đổi vẫn biết lúc đó ra sao): **để sau**, chỉ làm khi có yêu cầu kiểm toán.

### 5.6. Hai tín hiệu học khác, không tốn token

- Bài trích nguồn không có trong sổ (`EVID01` đã phát hiện): hiện ở tab Sổ nguồn như một gợi ý tên miền.
- Bài có con số không truy được về kho (`FactRule`, `KnowledgeFigureRule`): hiện ở tab Nghiên cứu như "số liệu chưa có căn cứ trong kho".

Cả hai lấy từ dữ liệu bước `grade` hoặc tính lại khi mở trang. Không tạo bảng "hộp gợi ý" riêng.

---

## 6. Một ví dụ đi hết chuỗi: bài "Cải thiện tiểu đường tuýp 2 bằng ăn uống"

Giả định: công ty bán thực phẩm cho người tiểu đường. Trí tuệ dùng mẫu sức khỏe: `risk_class = high`, `ai_reliance = must_cite`. Vì vậy **không mục nào được tự nhận**.

| Mắt xích | Hệ thống làm gì | Ghi vào đâu | Hiện ở đâu | Người làm gì |
|---|---|---|---|---|
| Trước đó, một lần | Đọc website công ty; rút mục về công ty và sản phẩm | `knowledge_sources`, `knowledge_items` (`proposed`) | Trang kho: Hộp duyệt | **Chốt Sổ nguồn** (ví dụ Bộ Y tế, WHO, hội chuyên ngành; do người chọn và xếp bậc). **Duyệt** mục sản phẩm và công dụng |
| 1. Từ khóa | Như hiện nay | `keywords` | Tab Từ khóa và kế hoạch | |
| 2. Kế hoạch | Như hiện nay | `keyword_clusters`, `articles` | Tab Từ khóa và kế hoạch | |
| 3. Nghiên cứu | Tìm kho. Có 2 mục sản phẩm, thiếu kiến thức về bệnh. Gọi `brain_build`: tải tài liệu từ **tên miền đã xác nhận**, tách thành mục kèm đoạn gốc | `knowledge_sources` (`origin = registry`), `knowledge_items` (`proposed`, tầng C), `knowledge_gaps`, dữ liệu bước `research` | Tab Nghiên cứu: "6 mục chờ duyệt". Trang kho: Hộp duyệt, rủi ro cao xếp trên | **Cổng 1: người có chuyên môn duyệt từng mục**, đối chiếu đoạn gốc và trang nguồn. Bài **dừng ở đây** |
| 4. Viết | Nhận mục đã duyệt, mục sản phẩm đã duyệt, điều cấm, danh sách nguồn được trích | `article_versions`, `knowledge_item_usages`, `sources_cited` | Tab Nội dung | |
| 5. Kiểm và duyệt | `EVID01`: đủ bằng chứng chưa, có nguồn bị chặn không. Luật điều cấm. Luật số liệu | Dữ liệu bước `grade` | Tab Nghiên cứu: bốn nhóm nguồn kèm trạng thái kiểm | **Cổng 2: người có chuyên môn duyệt bài.** Thiếu bằng chứng thì bài giữ ở nháp (đã có) |
| 6. Xuất bản | Chỉ tạo bản nháp trên CMS như hiện nay | `publications` | Tab Xuất bản | **Cổng 3: người bấm đăng** |
| Đo lường, về sau | Trang nguồn đổi nội dung thì báo mục có thể đã cũ và các bài đã dùng | `knowledge_items` (đề xuất bản mới) | Trang kho: Cảnh báo | Duyệt bản mới; quyết có làm mới bài |

Trong ví dụ này web nói chung **không** được dùng làm bằng chứng. Mẫu sức khỏe vẫn chưa có chuyên gia y khoa hay pháp lý rà (đã ghi trong kế hoạch P2); thiết kế này không thay được việc đó.

---

## 7. Bên trong `brain_build`

| # | Bước | AI? | Dùng lại |
|---|---|---|---|
| 1 | Chọn trang: sitemap của website, URL người dán, hoặc kết quả tìm trong tên miền đã xác nhận | Không | `SitemapCrawlerService` |
| 2 | Tải an toàn | Không | `UrlSafetyService::validateUrl` `:39`, cần gia cố |
| 3 | Trích phần chữ (bỏ menu, chân trang, mã lệnh) | Không | Viết mới; `HtmlSeoExtractor` chỉ lấy siêu dữ liệu |
| 4 | Bỏ qua trang không đổi (so băm) | Không | `content_hash`, `KnowledgeDedupe` |
| 5 | Tách thành mục có đoạn nguyên văn, gắn chủ đề | **Có**, tuyến `knowledge` (model rẻ) | `KnowledgeAiIngest::run` |
| 6 | Tìm trùng, mâu thuẫn | Không | `KnowledgeDedupe`, `KnowledgeConflicts` |
| 7 | Phân tầng A/B/C, tự nhận hoặc đưa vào Hộp duyệt | Không | `KnowledgeReview` |

Chỉ bước 5 tốn token. Sửa lời nhắc của `KnowledgeAiIngest` (thêm chủ đề, loại `product`) cần Dev1 rà.

**Ngân sách (đề xuất, chưa đo):** lượt xây đầu đọc tối đa 30 trang; giữ trần 5.000 đồng mỗi trang đang có, tức trần 150.000 đồng mỗi lượt. Lượt lấp khoảng trống cho một bài: tối đa 5 trang. Trần ngày của website theo bản pipeline; trần nào chạm trước thì dừng và báo "đã đọc 18/30 trang".

**Chạy nền:** trước AG-B10, mỗi lượt lịch xử lý vài trang. Lượt xây đầu có thể mất một đến hai giờ **(suy đoán)**. Bước `research` của bài không chờ được lâu như vậy, nên trước AG-B10 nó chỉ đọc tối đa 2–3 trang hoặc chỉ ghi khoảng trống.

**Tải an toàn (chống SSRF).** `UrlSafetyService` đã kiểm giao thức, tên nội bộ, phân giải DNS và dải địa chỉ bị chặn, có danh sách tên miền cho phép. Cần thêm trước khi tải tên miền ngoài **(nhận định từ đọc mã, chưa thử khai thác)**:
1. Các bộ tải hiện có cho Guzzle tự theo 3 lần chuyển hướng (`SeoAuditService.php:27`), đích chuyển hướng có vẻ không được kiểm lại. Bộ tải mới tắt tự chuyển hướng, kiểm từng bước.
2. Ghim địa chỉ IP đã kiểm khi gửi yêu cầu.
3. Giới hạn kích thước (đề xuất 2 MB), thời gian chờ, chỉ nhận HTML và văn bản.
4. Với tên miền ngoài: tôn trọng `robots.txt`, giãn cách yêu cầu.

Giai đoạn đầu chỉ tải tên miền của chính website, nên rủi ro thấp.

---

## 8. Tính năng nào giữ, cắt, để sau

| Tính năng (bản 1) | Giúp mắt xích nào | Quyết định |
|---|---|---|
| Xây kho từ website công ty | 3: bài có thông tin công ty, sản phẩm | **Giữ**, làm trước |
| Mục sản phẩm | 3, 4: bài nhắc đúng sản phẩm, đúng công dụng | **Thêm mới** |
| Bước `research` gọi `brain_build` khi thiếu | 3: vòng lặp bài ↔ kho | **Giữ**, là lõi |
| Nguồn trích trỏ về bản ghi có URL và trạng thái kiểm | 3, 5: người duyệt đối chiếu được | **Giữ**, là lõi |
| Phân tầng rủi ro, Hộp duyệt xếp rủi ro cao lên trên | 5: người duyệt không ngập | **Giữ**, dùng Hộp duyệt đang có, không làm trang mới |
| Khởi tạo một bước | 3: kho có dữ liệu trước bài đầu tiên | **Giữ**, thu gọn |
| AI điền nháp Hồ sơ doanh nghiệp | 4: bài có giọng và thông điệp | **Giữ**, sau lượt xây đầu |
| Mức tự chủ của kho | 5 | **Giữ hai lựa chọn do bạn chọn**: "Duyệt trước" (mọi mục chờ duyệt, trừ tầng A) và "Toàn quyền cho đội AI" (AI tự nhận cả tầng B; tầng C chỉ tự nhận khi bạn tắt cổng chuyên gia, xem Q1). Dùng cùng công tắc với bản pipeline §6 |
| Làm mới định kỳ | Đo lường → làm mới bài | **Để sau** (cần AG-B10). Giữ phần rẻ: so băm, chỉ gọi AI khi trang đổi |
| Hộp gợi ý riêng, bảng `knowledge_suggestions`, 5 loại gợi ý | 3 | **Cắt.** Khoảng trống, mục chờ duyệt, tên miền gợi ý đã có chỗ chứa |
| Học từ bản sửa của người (`new_fact`, `outdated`) | 3 cho bài sau | **Để sau.** Chưa biết câu nào sinh từ mục nào nên dễ báo sai |
| Tổng quan tự sinh, bảng `brain_overviews` | Không rõ | **Cắt.** Không làm bài nào tốt hơn; kết quả lượt xây đã cho biết kho có gì |
| Hỏi đáp trên kho | Gián tiếp (tra cứu) | **Để sau.** Tab Nghiên cứu đã trả lời "kho biết gì cho bài này" |
| Bảng `brain_build_runs` | | **Cắt.** Ghi phiên theo bảng phiên chung của bản pipeline |
| Bảng nối mục–nhiều nguồn, đếm "số nguồn độc lập" | 5 | **Để sau**, cùng lúc với tìm web |
| Gom tab trang kho | | **Cắt khỏi phạm vi.** Đụng vùng dùng chung, không làm bài tốt hơn |

### 8.1. Khởi tạo một bước

```
┌────────────────────────────────────────────────────────────────────┐
│ Tạo Trí tuệ cho website                                            │
│ Website      [ thucphamtieuduong.vn                          ▾ ]   │
│ Doanh nghiệp của bạn làm gì? (2–3 câu)                             │
│ [ Bán thực phẩm và đồ uống cho người tiểu đường.                 ] │
│                                                                    │
│ Lĩnh vực nhận diện: Sức khỏe (rủi ro cao)                 [ Đổi ]  │
│  → Mọi mục kiến thức cần người có chuyên môn duyệt.                │
│ Sẽ đọc tối đa 30 trang trên website của bạn.                       │
│ Chi phí AI: tối đa 150.000 đ (trần), dừng khi chạm trần.           │
│                              [ Hủy ]   [ Bắt đầu xây kho ]         │
└────────────────────────────────────────────────────────────────────┘
```

### 8.2. Tab "Nghiên cứu và nguồn" của bài sau thay đổi

```
┌ Nghiên cứu và nguồn ───────────────────────────────────────────────────────┐
│ ⚠ Kho còn thiếu cho bài này: 6 mục chờ duyệt, bài đang dừng trước bước viết │
│                                      [ Mở Hộp duyệt ]  [ Viết với mục đã có ]│
├────────────────────────────────────────────────────────────────────────────┤
│ KIẾN THỨC ĐÃ ĐƯA CHO AI (từ Kho trí tuệ)                                    │
│  • Chỉ số đường huyết của thực phẩm · bản 2 · duyệt bởi BS. Lan 09/11       │
│    nguồn: moh.gov.vn/…  · trong Sổ nguồn, bậc 1 · tải 08/11 · [đoạn gốc]    │
│ SẢN PHẨM LIÊN QUAN CỦA CÔNG TY                                              │
│  • Bánh yến mạch không đường · đã duyệt · thucphamtieuduong.vn/banh-yen-mach│
│ NGUỒN CHUYÊN NGÀNH (trong Sổ nguồn do bạn chốt)                             │
│  • who.int/… · bậc 1 · tải 10/11 · 4 mục chờ duyệt                          │
│ TỪ WEB (chưa xác nhận)                              chưa bật tìm web        │
│ NGUỒN AI TỰ NÊU, KHÔNG KHỚP BẢN GHI NÀO                                     │
│  • "Hiệp hội Đái tháo đường Hoa Kỳ 2023" · ngoài Sổ nguồn · chưa kiểm       │
│ SỐ LIỆU TRONG BÀI CHƯA CÓ CĂN CỨ TRONG KHO                                  │
│  • "giảm 30% nguy cơ biến chứng"                                            │
│ Hồ sơ doanh nghiệp · Dàn ý AI lập                              (như hiện nay)│
└────────────────────────────────────────────────────────────────────────────┘
Ghi chú cố định: "Kiểm ở mức cả bài; chưa nối từng câu với từng nguồn."
```

### 8.3. Hộp duyệt trên trang kho (màn hình đang có, thêm ba thứ)

Sắp rủi ro cao lên trên; mỗi mục hiện dữ kiện nguồn (3.1) và "bài nào đang chờ mục này"; có dòng "N mục đã tự nhận trong lượt xây [Xem / hoàn tác]". Trang Trí tuệ là vùng dùng chung: thay đổi đi PR riêng đã thống nhất.

---

## 9. Mô hình dữ liệu

Migration gần nhất là `2026_11_19_000001`. Migration mới đặt ngày **từ `2026_11_20`**, đi **PR riêng** cùng Model, Dev1 rà. Tên cột là đề xuất.

**Không thêm bảng nào ở các giai đoạn đầu.** Chỉ thêm cột:

| Bảng | Cột mới | Dùng cho |
|---|---|---|
| `knowledge_sources` | `origin` (`own_site`/`registry`/`search`/`user`), `fetched_at`, `final_url`, `http_status` | Tab Nghiên cứu và tab Nguồn hiện nguồn gốc, ngày tải |
| `knowledge_items` | `risk_tier` (`A`/`B`/`C`), `review_mode` (`manual`/`auto`) | Xếp Hộp duyệt; nhãn "tự nhận"; hoàn tác |

Không cần migration:
- Loại mục `product`: thêm vào `KnowledgeItem::TYPES` (cột `string(12)`).
- Mức tự chủ và trạng thái lượt xây: khóa mới trong `brains.settings` (JSON đã có).
- Kết quả bước `research`: `agent_run_steps.data` (JSON đã có).
- Phiên chạy của `brain_build`: theo bảng phiên của bản pipeline (bản đó đề xuất `pipeline_runs`). Trước khi có, tiến độ lượt xây tạm giữ trong `brains.settings`.

Dùng lại nguyên: `knowledge_topics`, `knowledge_item_versions`, `knowledge_item_usages`, `knowledge_gaps`, `brain_sources`, `brand_profiles`.

Dòng cũ có cột mới bằng null, hành vi không đổi. Chạy được trên SQLite và MariaDB 10.6; không vectơ.

Phần chữ của trang lưu ở `knowledge_sources.raw_text` như tài liệu dán hiện nay. Với trang bên ngoài xem rủi ro bản quyền (12.2).

---

## 10. Nhà cung cấp tìm kiếm

Chỉ cần khi làm tìm tự động (giai đoạn 4). **Tôi không kiểm được bảng giá hiện hành nên không ghi con số; mọi nhận xét chi phí là tương đối và cần kiểm lại.**

| Tiêu chí | (1) API tìm kiếm riêng (ví dụ Tavily, Exa, Brave) | (2) Công cụ tìm kiếm sẵn của nhà cung cấp LLM | (3) Chỉ website công ty + URL người đưa |
|---|---|---|---|
| Chi phí | Theo lượt tìm. Cần kiểm lại | Theo lượt tìm cộng token kết quả; khó đoán vì model tự quyết số lượt. Cần kiểm lại | Không có phí tìm |
| Giới hạn theo tên miền | Thường có **(cần kiểm từng nhà)**; ta nhận URL rồi tự kiểm và tự tải | Tùy nhà **(cần kiểm lại)**; ta không tự tải nên khó kiểm phía mình | Kiểm soát hoàn toàn |
| Truy về nguồn | Tốt: giữ được quy tắc đoạn nguyên văn | Yếu hơn: trích dẫn do model trả | Tốt nhất |
| Đụng mã lõi | Không (một dịch vụ mới) | **Có**: sửa `AbstractLlmProvider`, mỗi nhà một kiểu | Không |
| Rủi ro chèn lệnh | Trung bình, đóng khung như hiện nay | Cao hơn: nội dung web vào thẳng ngữ cảnh | Thấp |
| Mức tự động | Cao | Cao | Thấp cho nguồn ngoài |

**Khuyến nghị:** giai đoạn 1–3 dùng (3). Giai đoạn 4 dùng (1) sau một giao diện `SearchProvider`, và **ban đầu chỉ tìm trong các tên miền đã xác nhận trong Sổ nguồn**. Lý do là kiểm soát, không phải giá: ta tự tải, tự kiểm đoạn gốc, không sửa lớp gọi AI lõi. Không khuyến nghị (2) cho việc xây kho.

Chọn nhà cụ thể: chưa đủ căn cứ. Thử hai nhà trên 20 truy vấn tiếng Việt thật rồi chốt.

Ước lượng tương đối: mỗi khoảng trống cần 1–3 lượt tìm và đọc 3–5 trang. Chi phí token đọc trang nhiều khả năng lớn hơn phí tìm **(suy đoán)**. Nên đặt trần theo **số trang đọc mỗi tháng mỗi website**.

---

## 11. Lộ trình

Vùng dùng chung: `BrainPage`, `BrainLibraryPage`, view `brain-*`, `components/studio/*`, `seo-studio.css`. Mã lõi cần Dev1: `AgentRunOrchestrator`, `AbstractLlmProvider`, lời nhắc.

| Giai đoạn | Phạm vi | Tệp | Cần Dev1 | Cách kiểm |
|---|---|---|---|---|
| **G0. Cột mới** | Các cột ở phần 9 | Migration từ `2026_11_20`; `KnowledgeSource`, `KnowledgeItem` | Rà migration, Model (PR riêng) | Migrate, rollback, migrate lại trên SQLite và MariaDB 10.6; test cũ vẫn đạt |
| **G1. Nguồn hiện đúng trong bài** (không AI mới, không tải web) | Tab Nghiên cứu: nguồn suy ra từ `knowledge_item_usages` kèm URL, đoạn gốc; đối chiếu `sources_cited` với Sổ nguồn; số liệu chưa có căn cứ; đổi nhãn "đã đưa cho AI" | `ViewArticle.php`, `workspace.blade.php` (phối hợp với phiên đang giữ hai tệp này) | Không | Test tính năng: bài có mục dùng → hiện URL nguồn; nguồn bị chặn, ngoài sổ hiện đúng nhãn; không có bản nào thì không lỗi |
| **G2. Xây kho từ website công ty + sản phẩm** | Bộ tải an toàn (chỉ tên miền của website), trích chữ, điều phối lượt xây, phân tầng, loại `product`, khởi tạo một bước, Hộp duyệt xếp theo rủi ro, nháp Hồ sơ doanh nghiệp | Mới: `app/Services/Knowledge/Build/{SafePageFetcher,ReadableTextExtractor,BrainBuildService,RiskTiering}.php`, lệnh lịch `brain:build-tick`. Sửa: `KnowledgeAiIngest`, `KnowledgeItem::TYPES`, `routes/console.php` | Rà lời nhắc tách; đồng ý lệnh lịch tạm; PR vùng dùng chung cho Hộp duyệt | Test bộ tải (từ chối localhost, IP nội bộ, chuyển hướng sang tên miền khác, quá cỡ); test phân tầng (giá luôn C; Trí tuệ `must_cite` không tự nhận); test dừng khi chạm trần và chạy tiếp; test hoàn tác. Lên mức 3: chạy AI thật trên một website, đo số mục, tỉ lệ hoàn tác, chi phí |
| **G3. Bước `research` và nguồn chuyên ngành do người đưa** | Bước `research` trong chuỗi viết gọi `brain_build`; 2 chỗ cho sản phẩm; dừng trước bước viết với rủi ro cao; người dán URL thuộc tên miền đã xác nhận; bước viết chỉ trích nguồn trong danh sách; khoảng trống ghi cả khi tìm được ít | Sửa: `AgentRunOrchestrator`, `AbstractLlmProvider` (lời nhắc), `KnowledgeContext`. Gia cố bộ tải cho tên miền ngoài | **Có, nặng.** Nên để Dev1 làm hoặc rà phần lõi | Test với AI giả: kho thiếu → có bước `research`, có mục `proposed`, bài rủi ro cao dừng đúng chỗ; nguồn ngoài danh sách bị đánh dấu. Chạy thật bài tiểu đường ở phần 6 làm kịch bản nghiệm thu |
| **G4. Tìm tự động** | `SearchProvider` + một nhà cung cấp; tìm trong tên miền đã xác nhận; sau đó mới tới web nói chung | Mới: `app/Contracts/SearchProvider.php`, `app/Services/Search/*`; khóa trong `.env`, không đưa vào kho mã (kho công khai) | AG-B10 nên có; rà bảo mật; trần chi phí chung | Nhà cung cấp giả trong test; URL ngoài tên miền cho phép bị loại ở phía mình; thử 20 truy vấn tiếng Việt thật |
| **G5. Làm mới và để sau** | So băm định kỳ, báo mục cũ và bài bị ảnh hưởng; xem lại các mục "để sau" ở phần 8 | | AG-B10 | Trang đổi → có đề xuất bản mới; trang không đổi → không tốn token |

G1 nhỏ, không phụ thuộc ai, và sửa ngay chỗ người duyệt đang thấy "nguồn AI tự nêu, chưa kiểm". Nên làm trước.

---

## 12. Quyết định cần chốt, rủi ro, điều chưa kiểm

### 12.1. Quyết định cần chủ dự án chốt

| # | Câu hỏi | Khuyến nghị |
|---|---|---|
| Q1 | Mức tự chủ của kho | **Đã chốt:** bạn chọn "Duyệt trước" hoặc "Toàn quyền". Mặc định Duyệt trước. Ở Toàn quyền, tầng C (giá, chính sách, số liệu y tế, pháp lý, tài chính) vẫn chờ người duyệt trừ khi bạn tắt công tắc cổng chuyên gia từng website (có xác nhận và nhật ký); khi tắt, hệ thống vẫn ghi nhãn "tự nhận" và cho hoàn tác |
| Q2 | Cho **tự nhận không cần duyệt** thông tin thông thường của công ty lấy từ website công ty? (đổi quy tắc "AI không tự duyệt") | **Có**, kèm nhãn và hoàn tác; không áp dụng cho giá, chính sách, lĩnh vực rủi ro cao |
| Q3 | Bài rủi ro cao dừng trước bước viết khi kiến thức chưa duyệt | **Đã chốt:** có ở "Duyệt trước"; ở "Toàn quyền" chỉ dừng khi cổng chuyên gia còn bật |
| Q4 | Tìm web tự động | **Đã chốt: làm**, sau cùng (G4), bắt đầu chỉ trong tên miền đã xác nhận |
| Q5 | Nhóm nhà cung cấp tìm kiếm | **API tìm kiếm riêng**; chọn nhà cụ thể sau khi thử và kiểm giá |
| Q6 | Ngân sách: trần lượt xây đầu, trần mỗi bài cho nghiên cứu, trần tìm kiếm mỗi tháng mỗi website | 30 trang và 150.000 đ cho lượt đầu; 5 trang mỗi bài; trần tìm kiếm do bạn định sau khi có giá |
| Q7 | **Danh sách nguồn tin cậy theo lĩnh vực**: ai chốt, ai xếp bậc? Lĩnh vực sức khỏe cần người có chuyên môn | Admin và người duyệt chốt như hiện nay; mẫu chỉ gợi ý; sức khỏe phải có chuyên gia xác nhận trước khi dùng |
| Q8 | Sản phẩm: chấp nhận cách tối thiểu (mục loại `product` rút từ trang sản phẩm), chưa nối danh mục Magento? | **Có** |
| Q9 | Ai được duyệt mục AI đề xuất (đang ghi "tính sau") | Giữ admin và người duyệt |
| Q10 | Lưu phần chữ của trang bên ngoài không, giữ bao lâu | Lưu để đối chiếu nội bộ, không hiện lại đoạn dài; thời hạn do bạn định sau khi hỏi người có chuyên môn pháp lý |

### 12.2. Rủi ro

| Rủi ro | Cách giảm | Còn lại |
|---|---|---|
| AI đọc sai hoặc bịa | Đoạn nguyên văn bắt buộc; con số phải có trong đoạn gốc; tầng C không tự nhận; hoàn tác | Không bắt được **diễn giải sai ý**. Chưa có số liệu AI thật |
| Nội dung y tế sai | Ba cổng người duyệt (phần 6); chỉ nguồn trong sổ được tính; điều cấm chặn cứng | Kiểm ở mức cả bài; mẫu sức khỏe chưa có chuyên gia rà |
| Công dụng sản phẩm bị nói quá | Chỉ nói điều có trong mục đã duyệt và "điều được phép khẳng định" | Dựa vào lời nhắc; cần luật kiểm riêng nếu thấy lọt |
| Kiến thức cũ | Hiện ngày tải; giá và chính sách luôn duyệt; so băm định kỳ (để sau) | Trang không ghi ngày thì không biết cũ mới |
| AI gán nhầm loại (giá thành `fact`) nên lọt tự nhận | Quy tắc phụ: có con số kèm đơn vị tiền thì luôn tầng C | Các kiểu nhầm khác chưa lường hết |
| Bản quyền | Mục là dữ kiện diễn đạt lại kèm nguồn; đoạn gốc ngắn, dùng nội bộ; tôn trọng `robots.txt` | Tôi không phải luật sư; cần người có chuyên môn xem |
| SSRF | `UrlSafetyService`; gia cố chuyển hướng, ghim IP; đầu tiên chỉ tải website công ty | Phần gia cố chưa viết, chưa thử |
| Chèn lệnh qua nội dung web | Đóng khung, lược đồ chặt, AI không có công cụ | Không chặn tuyệt đối; hậu quả giới hạn ở mục đề xuất |
| Chi phí | Trần theo trang, lượt, ngày; chỉ gọi AI khi trang đổi; dừng và báo | Ước lượng chưa đo |
| Va chạm với việc người khác | G1, G2 tránh mã lõi; phần lõi dồn vào G3 cho Dev1 | Tab Nghiên cứu đang có phiên khác sửa |

### 12.3. Điều tài liệu này chưa kiểm được

- Chưa chạy gì với AI thật: chi phí, số mục, thời gian là ước lượng.
- Chưa thử khai thác điểm yếu chuyển hướng nêu ở phần 7.
- Chưa kiểm giá và khả năng lọc tên miền của các nhà cung cấp tìm kiếm.
- Chưa kiểm phía Magento có API đọc sản phẩm không; mã hiện tại không gọi.
- Chưa đo sitemap của website thật có đủ để nhận ra trang sản phẩm theo đường dẫn không.
- FULLTEXT trên MariaDB thật chưa được kiểm (theo tài liệu bàn giao); chất lượng tìm kho khi câu chữ khác nhau là chưa biết.
- Chưa đọc toàn văn `KHO_TRI_TUE_DESIGN.md`, `KNOWLEDGE_BASE_DESIGN.md`, `DATA_BACKBONE_DESIGN.md`; chỉ đối chiếu đề mục và vài đoạn.
- Bảng `pipeline_runs` là đề xuất của bản pipeline, chưa tồn tại.

