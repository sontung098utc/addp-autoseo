# Thiết kế: Kho trí tuệ tự động (AI tìm nguồn, tổng hợp, phân loại; người chỉ duyệt ngoại lệ)

Ngày viết: 10/11/2026. Trạng thái: **bản thiết kế, chưa có dòng mã nào**. Người đọc: người làm SEO, marketing, nội dung và lập trình viên.

Tài liệu liên quan: `KHO_TRI_TUE_RESEARCH_VA_KIEN_TRUC.md` (kiến trúc bốn tầng, lộ trình P1–P5), `reports/KHO_TRI_TUE_P2_KE_HOACH.md` (sổ nguồn, kiểm bằng chứng), `THIET_KE_PIPELINE_THEO_LOAI_VIEC.md` (pipeline theo loại việc; "xây/cập nhật kho trí tuệ" là một loại việc, chi tiết do tài liệu này quyết định).

Ba tài liệu thiết kế cũ hơn (`KHO_TRI_TUE_DESIGN.md`, `KNOWLEDGE_BASE_DESIGN.md`, `DATA_BACKBONE_DESIGN.md`) cùng hai tài liệu kiến trúc và bàn giao nằm ở bản checkout chính (`addp-autoseo/docs/seo-agent/`), không có trong nhánh này. Hai điểm tài liệu này **khác** với chúng, cần bạn và Dev1 chốt trước khi làm:
- `KNOWLEDGE_BASE_DESIGN.md` §3 ghi "Không làm: tự duyệt, tự tìm web ngoài (AGENTS.md §1.3)". Tài liệu này đề nghị cho tự nhận mục tầng A (quyết định Q2) và tìm web có kiểm soát ở giai đoạn 3 (Q4). Cả hai là **thay đổi quy tắc**, chỉ làm khi bạn đồng ý rõ ràng; nếu không, giai đoạn 1 vẫn làm được với mọi mục qua duyệt.
- `KHO_TRI_TUE_DESIGN.md` §4 dự kiến lấy trang của chính website "từ `crawled_urls` đã quét". Bảng đó không lưu phần chữ của trang (xem 1.3), nên phải tải lại trang.

Phần tổng quan và hỏi đáp có trích nguồn ở phần 6 cùng hướng với `KHO_TRI_TUE_DESIGN.md` §5–6; tài liệu này không lặp lại các sản phẩm tổng hợp khác ở đó (FAQ, từ điển, bản đồ chủ đề).

Quy ước trong tài liệu:
- Đường dẫn mã tính từ gốc kho mã. Số dòng đúng tại thời điểm đọc (10/11/2026) và có thể lệch khi mã thay đổi.
- Chỗ nào ghi **(suy đoán)** là nhận định chưa được kiểm bằng mã hoặc bằng chạy thật.
- Mức bằng chứng của toàn bộ phần đã có vẫn là 1–2 trên 4 (đã viết mã, đã chạy test với AI giả). Chưa có AI thật, chưa có staging, chưa có người dùng thật.

---

## 1. Hiện trạng ngắn gọn

### 1.1. Những việc người dùng đang phải làm bằng tay

| Việc | Nơi thực hiện | Ghi chú |
|---|---|---|
| Tạo Trí tuệ: đặt tên, chọn mẫu, chọn website | `app/Filament/Pages/BrainLibraryPage.php:134-148` | Sau khi tạo, hồ sơ chỉ có khung gợi ý; người dùng phải tự điền giọng văn, thuật ngữ, quy tắc. |
| Điền Hồ sơ lĩnh vực (11 trường) | `app/Models/BrainProfile.php:17-29`, lưu tại `app/Filament/Pages/BrainPage.php:200` | Giọng văn, cấu trúc, thuật ngữ, lập luận, ghi chú rubric, bài mẫu. |
| Điền Hồ sơ doanh nghiệp | `app/Models/BrandProfile.php:21` | Lĩnh vực, khách hàng, giọng, từ cấm, thông điệp chính. |
| Dán tài liệu vào kho | `app/Filament/Pages/KnowledgePage.php:238` (`pasteDoc`), `app/Services/Knowledge/KnowledgeIngest.php:24` | Chỉ nhận **văn bản dán vào**. Hàm có tham số `kind`/`url` nhưng không có chỗ nào tải trang từ URL. |
| Bấm "tách" tài liệu thành mục | `KnowledgePage.php:159` (tách theo quy tắc), `:168` (tách bằng AI) | Mỗi tài liệu phải bấm một lần. |
| Thêm từng mục kiến thức bằng tay | `KnowledgePage.php:229` | |
| Tạo chủ đề để phân loại | `KnowledgePage.php:252` | AI không tự tạo chủ đề (`KnowledgeAiIngest.php:349` trở đi). |
| Duyệt, từ chối, gộp, chọn bản đúng khi mâu thuẫn | `KnowledgePage.php:127-152`, `app/Services/Knowledge/KnowledgeReview.php:31-137` | Mọi mục AI đề xuất đều phải có người duyệt. |
| Thêm nguồn vào Sổ nguồn, xếp bậc, xác nhận | `BrainPage.php:237`, `:254`; `app/Services/Brain/BrainSourceRegistry.php:114-139` | |
| Chọn mức dựa vào AI, mức rủi ro | `BrainPage.php:221`; `app/Models/Brain.php:17-23` | |
| Xem "Khoảng trống" rồi tự viết mục lấp vào | `KnowledgePage.php:185` (`prefillFromGap`) | |

Trang kho hiện có tới mười tab (`resources/views/filament/pages/knowledge.blade.php:20-36`): Hộp duyệt, Hiểu biết, Nguồn, Sổ nguồn, Cảnh báo, Khoảng trống, Thêm kiến thức, Hồ sơ lĩnh vực, Hồ sơ doanh nghiệp, Cài đặt.

### 1.2. Những việc AI và hệ thống đã làm được

- **Tách tài liệu đã dán thành mục kiến thức có trích đoạn gốc** (`app/Services/Knowledge/KnowledgeAiIngest.php:170`). Giới hạn: 3.000 ký tự mỗi đoạn (`:29`), tối đa 12 đoạn (`:42`), 60 mục (`:47`), ngân sách 5.000 đồng mỗi tài liệu (`:53`). AI không được dùng công cụ, không truy cập web (`:152`).
- **Chống bịa ở khâu nạp:** mỗi mục phải có `source_span` xuất hiện đúng trong đoạn gốc và con số phải có trong đoạn gốc, nếu không sẽ bị loại (`KnowledgeAiIngest.php:263`). Mục luôn ở trạng thái `proposed`, không bao giờ tự duyệt (`:16-26`, `:349`).
- **Phát hiện trùng và mâu thuẫn theo quy tắc cố định** (`KnowledgeDedupe.php:8-47`, `KnowledgeConflicts.php:8`): cùng đối tượng và thuộc tính nhưng khác giá trị thì gắn cờ mâu thuẫn.
- **Nạp kiến thức vào bài viết:** tối đa 5 mục, mỗi mục tối đa 600 ký tự (`KnowledgeContext.php:18-20`), tìm bằng FULLTEXT hoặc LIKE (`KnowledgeRetriever.php:24`), chỉ lấy mục đã duyệt và còn hạn.
- **Ghi lại mục nào đã dùng trong phiên bản bài nào** (`KnowledgeContext.php:93`, bảng `knowledge_item_usages`).
- **Ghi "khoảng trống"** khi tìm không ra mục nào cho bài (`KnowledgeContext.php:62-65`, `:105`). Chỉ ghi khi kết quả bằng **không**, và chỉ ghi từ khóa chính.
- **Cảnh báo hết hạn, không dùng, nguồn đổi nội dung** (`KnowledgeFreshness.php:22-44`). Hệ thống không tự quét web để phát hiện thay đổi (`:38`).
- **Kiểm bằng chứng ở mức bài** khi Trí tuệ đặt `must_cite` (`app/Services/Content/Quality/Rules/EvidenceRule.php`), dựa trên Sổ nguồn đã xác nhận.
- **Thu thập website:** đọc sitemap, tối đa 500 URL (`app/Services/Crawler/SitemapCrawlerService.php:47`), kiểm SEO từng trang (`SeoAuditService.php:38`), có kiểm an toàn URL (`app/Services/Security/UrlSafetyService.php:39`).

### 1.3. Những thứ chưa tồn tại

1. **Không có tìm kiếm web.** Tìm trong `app/Services/Ai` và `app/Contracts` không thấy `web_search`, `grounding` hay khai báo công cụ nào. Lớp gọi AI chỉ sinh văn bản.
2. **Không có tải trang từ URL để nạp kiến thức.** Loại nguồn `url` có trong bảng (`database/migrations/2026_11_14_000001_create_knowledge_tables.php:17-29`) nhưng không có mã nào tải.
3. **Bộ thu thập không giữ nội dung trang.** Bảng `crawled_urls` (`database/migrations/2026_11_06_000002_*`, dòng 15-34) chỉ lưu tiêu đề, mô tả, H1, tóm tắt đề mục, điểm và lỗi SEO. Vì vậy "xây kho từ website đã thu thập" **bắt buộc phải tải lại trang** hoặc mở rộng bộ thu thập để giữ phần chữ đã làm sạch.
4. **Không có tổng quan tự sinh, không có hỏi đáp trên kho.**
5. **Không có gợi ý cập nhật kho khi viết bài**, ngoài bản ghi khoảng trống nói trên.
6. **Không có việc chạy nền.** `app/Jobs` chỉ có hai việc đăng bài; lịch chỉ có `agent:tick` mỗi 15 phút (`routes/console.php`), ghi chú rằng Dev1 sẽ thay bằng hàng đợi (AG-B10).
7. **Không có khái niệm "mức tự chủ" cho kho.** Mức tự chủ hiện chỉ có ở chiến dịch viết bài.
8. **Không nối từng khẳng định với từng nguồn.** Kiểm bằng chứng mới ở mức cả bài (đã ghi trong `KHO_TRI_TUE_P2_KE_HOACH.md`, mục Giới hạn).

---

## 2. Đánh giá thẳng: hiện tại có tiện không?

**Câu trả lời ngắn: chưa tiện.** Kho hiện là một công cụ quản trị kiến thức cẩn thận, an toàn, nhưng gần như mọi việc đều do người làm. Nó phù hợp với người quản trị dữ liệu hơn là người làm SEO hay viết nội dung.

### 2.1. Theo từng vai trò

**Người làm SEO.**
- Tiện: bài viết ghi rõ đã dùng mục kiến thức nào; có danh sách khoảng trống theo từ khóa; có kiểm nguồn trích.
- Không tiện: kho trống cho tới khi có người dán tài liệu, nên bài đầu tiên của website mới hầu như không được kho hỗ trợ. Khoảng trống chỉ ghi khi tìm ra **không** mục nào, nên trường hợp "có mục nhưng thiếu ý quan trọng" không được ghi nhận. Không có cách hỏi nhanh "kho đã biết gì về từ khóa này".

**Người làm marketing.**
- Tiện: có chỗ ghi thông điệp chính, từ cấm, điều được phép khẳng định.
- Không tiện: phải tự điền hai bộ hồ sơ với khoảng hai mươi trường, trong khi phần lớn thông tin đó đã nằm trên trang Giới thiệu, trang sản phẩm, trang chính sách của chính website. Không có bản tổng quan để đọc nhanh và kiểm xem hệ thống hiểu doanh nghiệp đúng chưa.

**Người viết và biên tập nội dung.**
- Tiện: mục đã duyệt được đưa vào bài; mục "không được nói" bị chặn cứng.
- Không tiện: khi đang sửa bài và phát hiện một thông tin đúng nhưng kho chưa có, không có nút nào để đưa nó vào kho; phải rời bài, mở trang kho, mở tab Thêm kiến thức, điền biểu mẫu. Mười tab là nhiều với người chỉ muốn viết.

**Người duyệt (approver).**
- Mọi mục đều vào Hộp duyệt với mức ưu tiên như nhau. Số điện thoại cửa hàng và liều dùng thuốc xếp chung một hàng. Khối lượng duyệt tăng tuyến tính theo lượng tài liệu, đây là điểm nghẽn rõ nhất nếu muốn tự động hóa.

### 2.2. So với trải nghiệm kiểu NotebookLM

| Trải nghiệm | NotebookLM (mô tả chung, không đối chiếu từng tính năng) | Kho trí tuệ hiện tại |
|---|---|---|
| Đưa nguồn vào | Thả tệp hoặc dán đường dẫn, hệ thống tự đọc | Chỉ dán văn bản; không tải URL |
| Tổng quan tự sinh | Có ngay sau khi nạp | Không có |
| Hỏi đáp có dẫn nguồn | Có, bấm vào trích dẫn để xem đoạn gốc | Không có |
| Gợi ý câu hỏi, chủ đề | Có | Không có |
| Tự tìm thêm nguồn | Có tùy phiên bản **(cần kiểm lại)** | Không có |
| Dùng kiến thức để tạo sản phẩm | Ghi chú, tóm tắt | **Có và mạnh hơn:** đưa vào bài viết, ghi lại mục đã dùng, chặn điều cấm |
| Duyệt và phiên bản hóa từng mục | Không phải trọng tâm | **Có:** trạng thái, phiên bản, hạn hiệu lực, mâu thuẫn |

Kết luận: phần "quản trị sau khi đã có kiến thức" của kho hiện tại là tốt và nên giữ. Phần còn thiếu là **đầu vào tự động** (tìm, đọc, tổng hợp) và **lối ra dễ dùng** (tổng quan, hỏi đáp, gợi ý ngay tại bài viết). Bản thiết kế này bổ sung hai phần đó mà không bỏ cơ chế duyệt.

---

## 3. Nguyên tắc thiết kế

**Nguyên tắc 1: Tự động là mặc định.** Sau khi khởi tạo, hệ thống tự tìm nguồn, đọc, tổng hợp, phân loại. Người dùng không phải bấm "tách" cho từng tài liệu.

**Nguyên tắc 2: Cấu hình tối thiểu.** Khởi tạo một bước: chọn website và viết vài câu mô tả doanh nghiệp. Mọi thiết lập khác có giá trị mặc định hợp lý và được suy ra từ website. Hồ sơ lĩnh vực và hồ sơ doanh nghiệp do AI điền nháp từ nội dung website, người dùng chỉ sửa.

**Nguyên tắc 3: Người chỉ xử lý ngoại lệ.** Hộp duyệt không còn là nơi mọi mục đi qua. Chỉ những mục thuộc nhóm rủi ro cao, có mâu thuẫn, hoặc thiếu tín hiệu tin cậy mới cần người xem.

**Nguyên tắc 4: Mọi mục truy được về nguồn.** Mỗi mục kiến thức gắn với ít nhất một nguồn cụ thể (trang nào, tải lúc nào, đoạn nguyên văn nào). Mục không có đoạn nguyên văn khớp với nguồn thì không được tạo. Quy tắc này đã có trong `KnowledgeAiIngest.php:263` và được giữ nguyên.

**Nguyên tắc 5: Nội dung từ web và tài liệu là dữ liệu, không phải chỉ dẫn.** Giữ cách đóng khung chống chèn lệnh đang dùng ở `KnowledgeAiIngest`.

### 3.1. Dung hòa "tự động" với "hệ thống không tự khẳng định nguồn nào uy tín"

Nguyên tắc cũ (`BrainSourceRegistry.php:13-17`, `KHO_TRI_TUE_P2_KE_HOACH.md`) vẫn giữ: **hệ thống không gán nhãn "uy tín" cho bất kỳ nguồn nào.** Tự động hóa đạt được bằng hai cơ chế khác, không bằng cách để AI chấm điểm uy tín.

**Cơ chế A: Phân tầng theo rủi ro của nội dung, không theo "độ tin" của nguồn.**

| Tầng | Nội dung | Ví dụ | Xử lý ở mức "Cân bằng" |
|---|---|---|---|
| **A. Thông tin của chính doanh nghiệp, lấy từ chính website của họ** | Loại `fact`, `process`, `faq`, `messaging`; không chứa giá, không thuộc lĩnh vực rủi ro cao | Địa chỉ, giờ mở cửa, mô tả dịch vụ, quy trình đặt hàng | **Tự nhận.** Ghi rõ "tự nhận từ website của bạn", hoàn tác được. |
| **B. Kiến thức chung hoặc từ nguồn bên ngoài** | Mọi loại không thuộc tầng C | Khái niệm trong ngành, số liệu thị trường | **Đề xuất**, kèm tín hiệu tin cậy; người duyệt. |
| **C. Rủi ro cao** | Loại `price`, `policy`; mọi con số y khoa, pháp lý, tài chính; loại `myth`, `forbidden`; mọi mục thuộc Trí tuệ có `risk_class = high` hoặc `ai_reliance = must_cite` | Giá, liều dùng, điều khoản bảo hành, quy định pháp luật | **Luôn cần người duyệt**, ở mọi mức tự chủ. |

Lý do tầng A được tự nhận: doanh nghiệp là nguồn có thẩm quyền về chính mình, và nội dung đó họ đã công bố. Rủi ro còn lại là trang cũ hoặc AI đọc sai; rủi ro này được giảm bằng đoạn nguyên văn bắt buộc, ngày tải, và nút hoàn tác.

Giá và chính sách vẫn thuộc tầng C dù lấy từ website của chính doanh nghiệp, vì trang cũ chứa giá cũ là tình huống thường gặp và sai giá gây hậu quả trực tiếp.

**Cơ chế B: Hiển thị tín hiệu tin cậy minh bạch thay cho nhãn "uy tín".** Mỗi mục đề xuất hiển thị các dữ kiện quan sát được, người duyệt tự kết luận:

- Tên miền nguồn, và nguồn đó là **website của bạn**, **có trong Sổ nguồn đã xác nhận (bậc mấy)**, hay **chưa có trong sổ**.
- Số nguồn độc lập (khác tên miền) cùng nói một ý.
- Ngày tải trang, và ngày xuất bản nếu trang có ghi.
- Có đoạn nguyên văn khớp hay không (luôn phải có).
- Có mâu thuẫn với mục đã duyệt hay không.

Hệ thống **không** cộng các tín hiệu này thành một điểm số "độ tin" duy nhất, vì một con số như vậy sẽ bị đọc thành lời khẳng định của hệ thống.

Nguồn bên ngoài do AI tìm thấy được ghi vào Sổ nguồn ở trạng thái `suggested`, chưa có bậc, đúng như cách mẫu sức khỏe đang làm (`BrainSourceRegistry.php:164`). Chỉ người dùng mới xác nhận và xếp bậc.

---

## 4. Pipeline xây kho

Trong `THIET_KE_PIPELINE_THEO_LOAI_VIEC.md` loại việc này có mã `brain_build`. Tài liệu đó quy định hợp đồng ghép nối (ghi phiên chạy, trần ngân sách, đăng ký loại việc); tài liệu này quy định các bước bên trong.

**Điểm cần khớp lại giữa hai tài liệu:** tài liệu pipeline ghi "duyệt mục kiến thức là bắt buộc". Thiết kế này đề nghị cho mục tầng A được tự nhận ở mức Cân bằng. Nếu bạn không đồng ý (quyết định Q2 ở phần 10), mọi mục vẫn qua duyệt và hai tài liệu khớp nhau không cần sửa.

### 4.1. Sơ đồ

```
 [1] Tìm nguồn            [2] Tìm ngoài           [3] Tải trang an toàn
 website, sitemap,   -->  (từ giai đoạn 3,   -->  kiểm URL, giới hạn cỡ,
 tài liệu đã dán          qua nhà cung cấp)       không theo chuyển hướng lạ
                                                          |
                                                          v
 [6] Tổng hợp thành   <-- [5] Khử trùng       <-- [4] Trích phần chữ
 mục có trích dẫn (AI)    (băm, so gần giống)     bỏ menu, chân trang
        |
        v
 [7] Phân loại, gắn   --> [8] Tìm mâu thuẫn   --> [9] Phân tầng rủi ro
 chủ đề, thẻ (AI)         và khoảng trống         A tự nhận / B, C vào hộp duyệt
```

### 4.2. Từng bước

| # | Bước | Dùng AI? | Đầu vào | Đầu ra | Tái sử dụng |
|---|---|---|---|---|---|
| 1 | **Tìm nguồn trong nhà**: lấy danh sách URL của website, chọn trang đáng đọc | Không ở vòng đầu (quy tắc theo đường dẫn và tiêu đề: giới thiệu, dịch vụ, sản phẩm, chính sách, hỏi đáp, liên hệ). Có thể thêm một lần gọi AI rẻ để xếp ưu tiên khi website có trên 100 trang. | Sitemap, bảng `crawled_urls` | Danh sách URL ứng viên, có thứ tự ưu tiên | `SitemapCrawlerService::discoverUrls` (`:47`) |
| 2 | **Tìm nguồn bên ngoài** (chỉ từ giai đoạn 3) | Tùy nhà cung cấp, xem phần 8 | Câu truy vấn sinh từ khoảng trống và chủ đề | URL ứng viên kèm tiêu đề, đoạn trích | Chưa có gì |
| 3 | **Tải trang an toàn** | Không | URL | HTML thô, mã trạng thái, URL cuối cùng | `UrlSafetyService::validateUrl` (`:39`), cần gia cố, xem 4.4 |
| 4 | **Trích phần chữ**: bỏ điều hướng, chân trang, mã lệnh; giữ đề mục, đoạn văn, bảng | Không | HTML | Văn bản sạch, tối đa 200.000 ký tự (giới hạn hiện có ở `KnowledgeIngest.php:18`) | Viết mới; `HtmlSeoExtractor` chỉ lấy siêu dữ liệu SEO nên không dùng lại trực tiếp được |
| 5 | **Khử trùng tài liệu**: băm nội dung, bỏ qua nếu trang không đổi so với lần trước | Không | Văn bản sạch | Tài liệu mới hoặc đánh dấu "không đổi" | `KnowledgeDedupe` (`:8-47`), cột `content_hash` đã có |
| 6 | **Tổng hợp thành mục kiến thức có trích dẫn** | **Có** (tuyến `knowledge`, model rẻ) | Từng đoạn 3.000 ký tự | Mục đề xuất, mỗi mục có `source_span` | `KnowledgeAiIngest::run` (`:170`) gần như nguyên vẹn |
| 7 | **Phân loại**: gán chủ đề có sẵn hoặc đề xuất chủ đề mới; gắn thẻ; xác định tầng rủi ro | **Có**, gộp vào cùng lần gọi của bước 6 để không tốn thêm lượt | Mục đề xuất, danh sách chủ đề hiện có | Chủ đề, thẻ, tầng A/B/C | Mở rộng lược đồ đầu ra của bước 6. Tầng C xác định bằng **quy tắc cố định** (loại mục, có con số, mức rủi ro của Trí tuệ), không để AI quyết. |
| 8 | **Tìm mâu thuẫn và khoảng trống** | Mâu thuẫn: không. Khoảng trống: một lần gọi AI rẻ cho cả kho, so danh sách chủ đề với danh sách từ khóa của chiến dịch. | Mục mới, mục đã duyệt, từ khóa chiến dịch | Cờ mâu thuẫn; danh sách "kho chưa biết gì về…" | `KnowledgeConflicts` (`:8`), `KnowledgeGaps` |
| 9 | **Phân tầng và chuyển đi** | Không | Mục kèm tầng và tín hiệu | Tầng A: trạng thái `approved` với `approved_by = 'auto'`. Tầng B, C: vào hộp duyệt. | `KnowledgeReview` cho phần duyệt tay |
| 10 | **Sinh tổng quan và hồ sơ nháp** | **Có**, một lần gọi sau khi xong | Mục đã nhận | Bản tổng quan một trang; bản nháp Hồ sơ doanh nghiệp và Hồ sơ lĩnh vực | Viết mới |

Bước 6 và 7 thay đổi lời nhắc của `KnowledgeAiIngest`. Thư mục `resources/prompts` thuộc Dev1; lời nhắc của `KnowledgeAiIngest` hiện nằm ngay trong lớp (`:152`), nhưng mọi thay đổi lời nhắc vẫn nên qua Dev1 rà.

### 4.3. Ngân sách và tần suất

Các con số dưới đây là **đề xuất ban đầu, chưa đo trên AI thật**. Cần chạy thử trên một website thật rồi chỉnh.

| Thông số | Đề xuất | Căn cứ |
|---|---|---|
| Số trang đọc ở lần xây đầu | Tối đa 30 trang | Bằng với thứ tự độ lớn của `runFullAudit` (50 trang, `SeoAuditService.php:92`); đủ cho website doanh nghiệp nhỏ |
| Trần cho mỗi trang | Giữ 5.000 đồng, 12 đoạn | Đang có (`KnowledgeAiIngest.php:42-53`) |
| Trần cho một lần xây | 30 trang × 5.000 = tối đa 150.000 đồng theo trần; chi phí thật dự kiến thấp hơn nhiều vì hầu hết trang ngắn hơn 12 đoạn **(suy đoán, cần đo)** | |
| Trần theo ngày của website | Theo `THIET_KE_PIPELINE_THEO_LOAI_VIEC.md` §5.3; trần nào chạm trước thì dừng | |
| Làm mới website của mình | Mỗi tuần kiểm băm nội dung của các trang đã đọc; **chỉ gọi AI cho trang đã đổi** | Trang không đổi thì không tốn token |
| Làm mới nguồn ngoài | Mỗi tháng, hoặc khi mục sắp hết hạn | `KnowledgeFreshness` đã có khái niệm hết hạn |
| Khi dừng vì hết ngân sách | Lưu vị trí, báo rõ "đã đọc 18/30 trang, dừng vì chạm trần", cho phép chạy tiếp | Không âm thầm bỏ dở |

Trước khi có hàng đợi AG-B10, lần xây chạy theo kiểu "mỗi lượt lịch xử lý vài trang" qua một lệnh lịch riêng, giống cách `agent:tick` đang làm. Cách này chậm (30 trang có thể mất một đến hai giờ) nhưng không cần hạ tầng mới.

### 4.4. Tải trang an toàn (chống SSRF)

`UrlSafetyService` đã kiểm: chỉ `http`/`https`, chặn `localhost` và tên nội bộ, phân giải DNS rồi đối chiếu danh sách dải địa chỉ bị chặn, có danh sách tên miền cho phép (`:12-29`, `:39`, `:120`).

Điểm cần gia cố trước khi cho tải URL do AI hoặc kết quả tìm kiếm đưa ra **(nhận định từ việc đọc mã, chưa thử khai thác)**:

1. **Chuyển hướng.** `SeoAuditService` cho Guzzle theo tối đa 3 lần chuyển hướng. Việc kiểm an toàn làm trước khi gửi yêu cầu, nên đích chuyển hướng có vẻ không được kiểm lại. Bộ tải cho kho phải tắt tự theo chuyển hướng và kiểm lại từng bước.
2. **Đổi DNS giữa lúc kiểm và lúc tải.** Nên ghim địa chỉ IP đã kiểm khi gửi yêu cầu.
3. **Giới hạn tài nguyên:** kích thước phản hồi (đề xuất 2 MB), thời gian chờ, chỉ nhận `text/html` và văn bản thuần.
4. **Tôn trọng `robots.txt`** với nguồn bên ngoài, và giãn cách giữa các yêu cầu tới cùng một tên miền.
5. Ở giai đoạn 1, chỉ cho tải URL thuộc **tên miền của chính website** (dùng tham số danh sách cho phép đã có). Việc này loại bỏ gần hết rủi ro trong giai đoạn đầu.

---

## 5. Học trong lúc viết bài: "Gợi ý cho kho"

### 5.1. Ý tưởng

Mỗi lần đội AI viết hoặc người dùng sửa bài, hệ thống có thể nhận ra những điều kho nên biết. Những điều đó được gom vào một **hộp gợi ý** thay vì bị bỏ qua. Người dùng nhận hoặc bỏ bằng một lần bấm.

### 5.2. Các loại gợi ý và nơi phát sinh

| Loại | Phát sinh khi | Cần AI? | Ví dụ |
|---|---|---|---|
| `gap` Thiếu kiến thức | Người viết AI báo "tôi cần thông tin X mà kho không có", hoặc tìm kho ra ít hơn ngưỡng | Có (thêm một trường vào đầu ra của bước viết) hoặc không (đếm kết quả) | "Kho chưa có thông tin về thời gian bảo hành" |
| `new_fact` Thông tin mới từ bản sửa của người | Người dùng sửa bài và thêm một câu chứa dữ kiện cụ thể | Có, một lần gọi rẻ so bản trước và bản sau | Người dùng sửa "giao trong 3 ngày" thành "giao trong 24 giờ" |
| `outdated` Mục có thể đã cũ | Người dùng sửa đúng câu được viết từ một mục kiến thức; hoặc luật `KnowledgeFigureRule` báo con số trong bài khác con số trong kho | Không bắt buộc | Mục "giá từ 500.000 đ" bị người dùng sửa thành 550.000 đ |
| `source` Nguồn nên thêm vào sổ | Bài trích một nguồn chưa có trong Sổ nguồn (luật `EVID01` đã phát hiện được trường hợp này) | Không | "Bài trích who.int, chưa có trong sổ" |
| `unverified_claim` Khẳng định chưa có căn cứ | Luật `FactRule` gắn cờ một con số không truy được về kho | Không | "Bài ghi '90% khách hàng hài lòng', kho không có số này" |

Ba loại cuối dùng lại tín hiệu mà các luật kiểm chất lượng đã sinh ra, nên **không tốn thêm token**. Hai loại đầu cần thay đổi nhỏ ở bước viết hoặc thêm một lần gọi AI rẻ.

### 5.3. Dữ liệu lưu cho mỗi gợi ý

- Thuộc Trí tuệ nào, website nào, phát sinh từ bài nào và phiên bản bài nào.
- Loại gợi ý (một trong năm loại trên).
- Nội dung đề nghị: tiêu đề, nội dung, loại mục, đối tượng/thuộc tính/giá trị nếu có.
- Căn cứ: đoạn nguyên văn trong bài hoặc trong bản sửa; mục kiến thức liên quan nếu có.
- Người hoặc bước sinh ra: `ai:writer`, `rule:EVID01`, `user_edit`.
- Tầng rủi ro A/B/C theo cùng quy tắc ở phần 3.1.
- Trạng thái: `open`, `accepted`, `dismissed`, `snoozed`; ai xử lý, lúc nào; mục kiến thức được tạo ra nếu nhận.
- Số lần lặp lại (cùng một gợi ý xuất hiện ở nhiều bài thì tăng số đếm, không tạo bản ghi mới).

### 5.4. Quan hệ với `KnowledgeGap` và `KnowledgeItemUsage`

- **`knowledge_gaps` giữ nguyên, không xóa, không đổi nghĩa.** Mỗi khoảng trống đang mở được hiển thị trong hộp gợi ý như một gợi ý loại `gap`. Có hai cách làm: (a) hộp gợi ý đọc cả hai bảng và gộp khi hiển thị, hoặc (b) khi ghi khoảng trống thì ghi thêm một gợi ý. Đề nghị cách (a) ở giai đoạn 1 vì không đụng vào `KnowledgeContext`, sau đó cân nhắc hợp nhất.
- Khi một gợi ý loại `gap` được nhận và mục mới được duyệt, cơ chế có sẵn `KnowledgeGaps::fillFrom` (`:32`) tự đánh dấu khoảng trống đã lấp.
- **`knowledge_item_usages` là căn cứ để sinh gợi ý `outdated`:** biết bài dùng mục nào ở phiên bản nào thì mới biết người dùng vừa sửa câu sinh từ mục nào. Lưu ý: hiện chỉ biết "bài dùng mục X", chưa biết "câu nào trong bài sinh từ mục X". Vì vậy ở giai đoạn đầu, gợi ý `outdated` chỉ sinh khi so khớp được **giá trị** của mục (con số, cụm từ) trong đoạn bị sửa. Trường hợp không khớp được thì không sinh gợi ý, để tránh báo sai.

### 5.5. Nơi hiển thị

1. **Trong không gian làm việc của bài viết, tab "Trí tuệ và nguồn"** (`resources/views/filament/article/workspace.blade.php:38`, khối nội dung `:234-269`). Tab này hiện có ba thẻ: Trí tuệ đang dùng, Kiến thức đã dùng trong bản mới nhất, Nguồn trích. Đề nghị thêm thẻ thứ tư **"Gợi ý cho kho từ bài này"**, mỗi dòng có nút **Nhận** và **Bỏ**. Tệp này đang do phiên làm việc khác xây, nên việc thêm thẻ cần phối hợp, không làm song song.
2. **Trong trang Trí tuệ:** hộp gợi ý gộp chung với hộp duyệt thành một danh sách "Cần bạn xem" (wireframe ở phần 6). Trang Trí tuệ và các view `brain-*` là vùng dùng chung, thay đổi cần PR riêng có thống nhất trước.
3. **Một con số trên bảng điều khiển:** "Kho có 7 gợi ý chờ bạn". Tài liệu pipeline đã vẽ sẵn chỗ này ("3 khoảng trống kiến thức được ghi khi viết bài").

### 5.6. Nhận bằng một lần bấm nghĩa là gì

- Gợi ý tầng A hoặc B: bấm **Nhận** tạo mục kiến thức ở trạng thái đã duyệt, người duyệt là người bấm. Điều kiện: người bấm có quyền duyệt (`KnowledgeReview::canManage`, `:19`). Người không có quyền duyệt bấm thì mục được tạo ở trạng thái `proposed`.
- Gợi ý tầng C: bấm **Nhận** mở biểu mẫu đã điền sẵn để người duyệt xem lại con số và nguồn rồi mới lưu. Không có nhận một chạm cho giá, y khoa, pháp lý.
- Gợi ý `outdated`: bấm **Nhận** tạo phiên bản mới của mục (cơ chế phiên bản đã có ở `knowledge_item_versions`), bản cũ còn trong lịch sử.
- Gợi ý `source`: bấm **Nhận** thêm nguồn vào sổ ở trạng thái `suggested`; xếp bậc vẫn là thao tác riêng của người dùng.
- Sau khi nhận, bài hiện tại **không tự viết lại**. Người dùng quyết định có chạy lại bước viết hay không.

---

## 6. Trải nghiệm người dùng

### 6.1. Mức tự chủ của kho

Lưu ở `brains.settings` (cột JSON đã có), khóa mới `autonomy`. Mặc định **Cân bằng**, cùng tên và cùng tinh thần với mức mặc định của chiến dịch mà bạn đã chốt.

| Mức | Tầng A (thông tin của mình, từ website mình) | Tầng B (kiến thức chung, nguồn ngoài) | Tầng C (giá, y khoa, pháp lý, chính sách, điều cấm) |
|---|---|---|---|
| **Thủ công** | Chờ duyệt | Chờ duyệt | Chờ duyệt |
| **Cân bằng** (mặc định) | Tự nhận, hoàn tác được | Chờ duyệt, có nút "nhận cả nhóm" | Chờ duyệt từng mục |
| **Tự động** | Tự nhận | Tự nhận **chỉ khi** nguồn nằm trong Sổ nguồn đã xác nhận bậc 1–2, hoặc có ít nhất hai nguồn độc lập đã xác nhận cùng nói; còn lại chờ duyệt | Chờ duyệt từng mục |

Ghi chú:
- Trí tuệ có `risk_class = high` hoặc `ai_reliance = must_cite` (ví dụ mẫu sức khỏe) **bị khóa ở mức Thủ công** cho tầng B và C, bất kể người dùng chọn gì. Tầng A vẫn theo mức đã chọn.
- Mức "Tự động" phụ thuộc vào Sổ nguồn do người xác nhận, nên vẫn đúng nguyên tắc "hệ thống không tự khẳng định nguồn uy tín". Đề nghị **chưa mở mức này** cho tới khi có số liệu chạy thật về tỉ lệ mục sai ở mức Cân bằng.
- Mục tự nhận luôn có nhãn "tự nhận", có trang liệt kê riêng, và hoàn tác được từng mục hoặc cả lượt xây.

### 6.2. Khởi tạo một bước

```
┌──────────────────────────────────────────────────────────────────────┐
│  Tạo Trí tuệ cho website                                             │
│                                                                      │
│  Website        [ nhakhoa-abc.vn                              ▾ ]    │
│                                                                      │
│  Doanh nghiệp của bạn làm gì? (2–3 câu là đủ)                        │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ Phòng khám nha khoa tại Đà Nẵng, chuyên niềng răng và          │  │
│  │ trồng răng implant cho người đi làm.                           │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                                                                      │
│  Hệ thống sẽ tự làm:                                                 │
│   • Đọc tối đa 30 trang trên website của bạn                         │
│   • Rút ra các mục kiến thức, mỗi mục kèm đoạn gốc                   │
│   • Điền nháp hồ sơ doanh nghiệp và giọng văn để bạn sửa             │
│                                                                      │
│  Lĩnh vực được nhận diện: Sức khỏe (rủi ro cao)                      │
│   → Giá, thông tin y khoa luôn cần người duyệt.        [ Đổi ]       │
│                                                                      │
│  Chi phí AI ước tính: tối đa 150.000 đ (trần). Dừng khi chạm trần.   │
│                                                                      │
│  ▸ Tùy chọn nâng cao (mức tự chủ, số trang, trần chi phí)            │
│                                                                      │
│                                  [ Hủy ]   [ Bắt đầu xây kho ]       │
└──────────────────────────────────────────────────────────────────────┘
```

Sau khi bấm, người dùng thấy tiến độ theo từng bước ("Đã đọc 12/30 trang, đã rút 86 mục, 61 mục tự nhận, 25 mục cần bạn xem") và có thể rời trang. Việc nhận diện lĩnh vực dùng danh sách mẫu đã có (`template_key`, `domain_key` trong bảng `brains`); nếu nhận diện sai, người dùng đổi bằng một lần chọn.

### 6.3. Tổng quan tự sinh

Trang đầu của mỗi Trí tuệ, thay cho việc mở ra là thấy bảng. Nội dung:

- **Hệ thống hiểu doanh nghiệp của bạn thế này** (5–8 câu, mỗi câu có số chú thích dẫn về mục kiến thức).
- **Các chủ đề kho đã biết**, kèm số mục mỗi chủ đề.
- **Kho chưa biết gì về…** (khoảng trống, đối chiếu với từ khóa của các chiến dịch).
- **Cần bạn xem:** số mục chờ duyệt, số mâu thuẫn, số mục sắp hết hạn.
- **Câu hỏi gợi ý** để bấm sang màn hình hỏi đáp.

Tổng quan sinh lại sau mỗi lượt xây hoặc khi số mục đã duyệt thay đổi quá một ngưỡng, không sinh lại mỗi lần mở trang.

### 6.4. Hộp "Cần bạn xem" (duyệt ngoại lệ và gợi ý chung một chỗ)

```
┌─ Trí tuệ: Nha khoa ABC ──────────────────────────────────────────────────────┐
│ [Tổng quan] [Cần bạn xem · 9] [Hỏi kho] [Kiến thức] [Nguồn] [Thiết lập]      │
├──────────────────────────────────────────────────────────────────────────────┤
│ Lọc: (•) Tất cả  ( ) Rủi ro cao · 3  ( ) Mâu thuẫn · 1  ( ) Từ bài viết · 4  │
│                                                                              │
│ ⚠ RỦI RO CAO · Giá                                          từ lượt xây 10/11 │
│   "Niềng răng mắc cài kim loại: từ 25.000.000 đ"                             │
│   Đoạn gốc: «Chi phí niềng răng mắc cài kim loại chỉ từ 25 triệu…»           │
│   Nguồn: nhakhoa-abc.vn/bang-gia · website của bạn · tải 10/11/2026          │
│   Trang không ghi ngày cập nhật.                                             │
│                              [ Xem trang gốc ]  [ Sửa ]  [ Bỏ ]  [ Duyệt ]   │
│ ──────────────────────────────────────────────────────────────────────────── │
│ ≠ MÂU THUẪN · Thời gian bảo hành implant                                     │
│   Mục đã duyệt: "10 năm"   ·   Mục mới: "trọn đời" (trang /implant)          │
│                     [ Giữ bản cũ ]  [ Dùng bản mới ]  [ Xem hai đoạn gốc ]   │
│ ──────────────────────────────────────────────────────────────────────────── │
│ ✎ TỪ BÀI VIẾT · Thông tin mới            bài "Niềng răng mất bao lâu" bản 3  │
│   Bạn đã sửa: "tái khám mỗi 4 tuần" → "tái khám mỗi 6 tuần"                  │
│   Kho đang ghi: "tái khám mỗi 4 tuần" (đã dùng trong 2 bài khác)             │
│                                    [ Bỏ ]  [ Để sau ]  [ Cập nhật kho ]      │
│ ──────────────────────────────────────────────────────────────────────────── │
│ ? THIẾU KIẾN THỨC · gặp ở 3 bài                                              │
│   "Kho chưa có thông tin về chính sách trả góp"                              │
│                         [ Bỏ ]  [ Tôi tự nhập ]  [ Tìm trên website của tôi ]│
│ ──────────────────────────────────────────────────────────────────────────── │
│ ▸ 61 mục đã tự nhận từ website của bạn trong lượt xây 10/11   [ Xem / hoàn tác ]│
└──────────────────────────────────────────────────────────────────────────────┘
```

Thứ tự sắp xếp: rủi ro cao trước, rồi mâu thuẫn, rồi gợi ý lặp lại nhiều lần. Mười tab hiện tại gom lại còn sáu; các chức năng cũ (dán tài liệu, thêm tay, khoảng trống, cảnh báo) vẫn còn nhưng nằm bên trong các tab mới.

### 6.5. Hỏi đáp trên kho, có trích dẫn

```
┌─ Hỏi kho ────────────────────────────────────────────────────────────────────┐
│ Phạm vi: (•) Chỉ kiến thức đã duyệt   ( ) Cả mục đang chờ duyệt              │
│                                                                              │
│ Bạn: Niềng răng ở phòng khám mất bao lâu và tái khám thế nào?                │
│                                                                              │
│ Kho: Thời gian niềng thường từ 18 đến 24 tháng tùy tình trạng răng [1].      │
│      Khách tái khám mỗi 6 tuần [2]. Kho chưa có thông tin về thời gian       │
│      đeo hàm duy trì sau khi tháo mắc cài.                                   │
│                                                                              │
│   [1] Mục "Thời gian niềng răng" · đã duyệt 10/11 · nguồn /nieng-rang        │
│       «Một ca niềng thông thường kéo dài 18–24 tháng…»                       │
│   [2] Mục "Lịch tái khám" · bản 2, đã duyệt 10/11 · nguồn: bạn sửa trong bài │
│                                                                              │
│   Kho chưa trả lời được một phần câu hỏi.   [ Ghi thành gợi ý cho kho ]      │
│                                                                              │
│ Gợi ý: [Chính sách bảo hành?] [Bảng giá implant?] [Kho thiếu gì cho từ khóa…]│
│ ┌──────────────────────────────────────────────────────────────┐ [ Hỏi ]    │
│ └──────────────────────────────────────────────────────────────┘            │
└──────────────────────────────────────────────────────────────────────────────┘
```

Quy tắc của màn hình này:
- Câu trả lời **chỉ dựa trên mục trong kho**. Không có mục liên quan thì trả lời "kho chưa có", không dùng hiểu biết chung của model để lấp. Phần này dựa vào lời nhắc và việc kiểm chú thích, nên **không bảo đảm tuyệt đối**; cần đo tỉ lệ trả lời ngoài kho khi chạy thật.
- Mỗi câu có chú thích; hệ thống kiểm rằng mọi số chú thích đều trỏ tới một mục thật đã được đưa vào ngữ cảnh. Câu không có chú thích hợp lệ được hiển thị kèm cảnh báo.
- Tìm mục dùng `KnowledgeRetriever` hiện có (FULLTEXT, không vectơ). Chất lượng tìm với câu hỏi diễn đạt khác từ ngữ trong kho sẽ hạn chế **(suy đoán, cần đo)**; đây là dữ kiện để quyết định sau này có cần vectơ hay không, đúng quyết định "chưa dùng vectơ lúc này".
- Hỏi đáp dùng tuyến model rẻ (`summary`), giới hạn số câu hỏi mỗi ngày theo website để kiểm soát chi phí.

---

## 7. Mô hình dữ liệu

Migration gần nhất trong nhánh là `2026_11_19_000001`. Mọi migration dưới đây đặt ngày **từ `2026_11_20` trở đi** và đi trong **PR riêng** cùng Model, để Dev1 rà. Tên bảng và cột là đề xuất, có thể đổi khi rà.

### 7.1. Dùng lại, không đổi

| Bảng | Dùng cho |
|---|---|
| `knowledge_sources` | Mỗi trang đã tải là một nguồn với `kind = 'url'`, `url`, `raw_text`, `content_hash`, `ai_log`. Các cột này đã có. |
| `knowledge_items`, `knowledge_item_versions` | Mục kiến thức và lịch sử phiên bản. |
| `knowledge_topics` | Phân loại hai cấp. |
| `knowledge_item_usages` | Căn cứ sinh gợi ý `outdated`. |
| `knowledge_gaps` | Khoảng trống; hiển thị gộp vào hộp gợi ý. |
| `brain_sources` | Nguồn ngoài do AI tìm thấy ghi ở trạng thái `suggested`. |
| `brains.settings` (JSON) | Thêm khóa `autonomy`, `build` (số trang, trần). Không cần migration. |

### 7.2. Bảng mới

**`brain_build_runs`** (mỗi lượt xây hoặc làm mới là một dòng)

| Cột | Ý nghĩa |
|---|---|
| `brain_id`, `website_id` | |
| `kind` | `initial`, `refresh`, `gap_fill`, `manual` |
| `status` | `queued`, `running`, `paused_budget`, `done`, `failed` |
| `stats` (JSON) | Số trang tìm thấy, đã tải, không đổi, số mục rút ra, tự nhận, chờ duyệt, bị loại |
| `cost_vnd`, `budget_vnd` | |
| `cursor` (JSON) | Vị trí để chạy tiếp |
| `started_by`, `started_at`, `finished_at` | |

Nếu tài liệu pipeline thống nhất một bảng phiên chạy chung cho mọi loại việc, bảng này nên nhập vào bảng chung đó thay vì tồn tại riêng. Cần khớp với Dev1.

**`knowledge_item_sources`** (nối mục với nguồn, nhiều–nhiều; đây là chỗ lưu "truy về nguồn")

| Cột | Ý nghĩa |
|---|---|
| `item_id`, `source_id` | |
| `source_span` | Đoạn nguyên văn trong nguồn này |
| `fetched_at` | Ngày tải bản được trích |
| unique (`item_id`, `source_id`) | |

Hiện một mục chỉ có một `source_id` và một `source_span`. Bảng nối cho phép đếm "số nguồn độc lập cùng nói", là một trong các tín hiệu tin cậy.

**`knowledge_suggestions`** (hộp gợi ý, phần 5.3)

| Cột | Ý nghĩa |
|---|---|
| `brain_id`, `website_id`, `article_id`, `article_version_id` | Nguồn gốc |
| `kind` | `gap`, `new_fact`, `outdated`, `source`, `unverified_claim` |
| `payload` (JSON) | Nội dung đề nghị |
| `evidence` (text) | Đoạn nguyên văn làm căn cứ |
| `related_item_id` | Mục liên quan (với `outdated`) |
| `risk_tier` | `A`, `B`, `C` |
| `origin` | `ai:writer`, `rule:<mã luật>`, `user_edit`, `qa` |
| `dedupe_hash`, `count` | Gộp gợi ý lặp lại |
| `status`, `resolved_by`, `resolved_at`, `result_item_id` | |

**`brain_overviews`** (tổng quan tự sinh; có thể thay bằng một khóa trong `brains.settings` nếu muốn tránh thêm bảng, nhưng bảng riêng giữ được lịch sử và chú thích)

| Cột | Ý nghĩa |
|---|---|
| `brain_id`, `body`, `citations` (JSON: số chú thích → `item_id`) | |
| `generated_at`, `items_fingerprint` | Dùng để biết khi nào cần sinh lại |

### 7.3. Cột mới trên bảng có sẵn

| Bảng | Cột | Ý nghĩa |
|---|---|---|
| `knowledge_items` | `risk_tier` (`A`/`B`/`C`, null với mục cũ) | Tầng rủi ro lúc tạo |
| `knowledge_items` | `review_mode` (`manual`/`auto`) | Phân biệt mục người duyệt và mục tự nhận. Có thể thay bằng quy ước `approved_by = 'auto'`; đề nghị dùng cột riêng để truy vấn và hoàn tác theo lượt cho rõ. |
| `knowledge_items` | `build_run_id` (null được) | Hoàn tác cả lượt xây |
| `knowledge_items` | `trust_signals` (JSON) | Ảnh chụp tín hiệu lúc tạo: loại nguồn (của mình / trong sổ bậc mấy / ngoài sổ), số nguồn độc lập, ngày tải, có mâu thuẫn không. Là dữ kiện, không phải điểm số. |
| `knowledge_sources` | `fetched_at`, `http_status`, `final_url`, `published_at` (null được) | Thông tin tải trang |
| `knowledge_sources` | `origin` (`own_site`/`registry`/`search`/`user`) | Nguồn đến từ đâu |
| `knowledge_sources` | `build_run_id` (null được) | |

Mục và nguồn cũ có các cột mới bằng null; hành vi hiện tại không đổi. Mọi bảng phải chạy được trên SQLite và MariaDB 10.6 như các lát trước; không dùng tính năng cần nâng MariaDB, không vectơ.

### 7.4. Về việc lưu nội dung trang

Lưu phần chữ đã làm sạch trong `knowledge_sources.raw_text` (đã là cách đang làm với tài liệu dán). Với 30 trang mỗi website thì dung lượng nhỏ. Với nguồn bên ngoài, xem rủi ro bản quyền ở phần 10: chỉ lưu để trích dẫn nội bộ, không hiển thị lại nguyên văn dài, và có thời hạn xóa.

---

## 8. Lựa chọn nhà cung cấp tìm kiếm

Phần này chỉ cần cho giai đoạn 3 trở đi. Giai đoạn 1 và 2 không tìm web.

**Về giá:** tôi không kiểm được bảng giá hiện hành của các nhà cung cấp trong lúc viết, nên **không ghi con số**. Mọi nhận xét về chi phí dưới đây là tương đối và **cần kiểm lại** trên trang giá chính thức trước khi quyết.

| Tiêu chí | (1) API tìm kiếm trả phí riêng (ví dụ Tavily, Exa, Brave Search API) | (2) Công cụ tìm kiếm sẵn có của nhà cung cấp LLM (Gemini grounding, Anthropic web search, OpenAI web search) | (3) Chỉ nguồn do người dùng đưa + website của mình |
|---|---|---|---|
| Chi phí | Tính theo lượt tìm; thường có gói miễn phí nhỏ. **Cần kiểm lại.** | Tính thêm theo lượt tìm, cộng token của kết quả đưa vào ngữ cảnh. **Cần kiểm lại**; thường khó dự đoán hơn vì model tự quyết số lượt tìm. | Không có phí tìm kiếm; chỉ có phí token đọc trang |
| Kiểm soát tên miền | Thường hỗ trợ lọc theo tên miền **(cần kiểm với từng nhà cung cấp)**; ta nhận URL rồi tự kiểm lại và tự tải | Tùy nhà cung cấp; có nơi hỗ trợ danh sách cho phép, có nơi không **(cần kiểm lại)**. Ta không tự tải trang nên khó kiểm lại phía mình. | Kiểm soát hoàn toàn |
| Truy về nguồn | Tốt: ta giữ URL, tự tải, tự lấy đoạn nguyên văn, giữ được quy tắc `source_span` | Yếu hơn: trích dẫn do model trả về; đoạn nguyên văn không phải lúc nào cũng có, khó kiểm "con số có trong đoạn gốc" | Tốt nhất |
| Chất lượng tiếng Việt | Chưa biết, **cần thử** với truy vấn tiếng Việt thật | Có lẽ tốt với nhà cung cấp có công cụ tìm kiếm lớn **(suy đoán)** | Phụ thuộc người dùng |
| Rủi ro chèn lệnh | Trung bình: ta đóng khung nội dung như đang làm | Cao hơn: nội dung web vào thẳng ngữ cảnh của model cùng lượt | Thấp |
| Phụ thuộc mã lõi | Thêm một dịch vụ mới, **không đụng `AbstractLlmProvider`** | Phải sửa lớp gọi AI lõi để hỗ trợ công cụ; mỗi nhà cung cấp một kiểu; cần Dev1 rà sâu | Không |
| Khóa và vận hành | Thêm một khóa API, một hóa đơn | Dùng khóa LLM đã có | Không |
| Mức tự động đạt được | Cao | Cao | Thấp: người vẫn phải đi tìm nguồn |

### Khuyến nghị

1. **Giai đoạn 1 và 2: phương án (3).** Không tốn phí tìm kiếm, rủi ro thấp, và đã giải quyết phần lớn sự bất tiện vì thông tin về chính doanh nghiệp nằm trên website của họ.
2. **Giai đoạn 3: phương án (1), sau một giao diện trừu tượng** (`SearchProvider` với một phương thức tìm, trả về URL, tiêu đề, đoạn trích). Lý do chính không phải giá mà là **kiểm soát**: ta nhận URL, tự kiểm an toàn, tự tải, tự kiểm đoạn nguyên văn, nên giữ nguyên được toàn bộ cơ chế chống bịa đang có; và không phải sửa lớp gọi AI lõi. Điều này cũng khớp với định hướng P3 trong tài liệu kiến trúc: tìm trong phạm vi tên miền của Sổ nguồn và kiểm lại tên miền ở phía mình.
3. **Không khuyến nghị phương án (2) cho việc xây kho.** Nó hợp với hỏi đáp tức thời hơn là với việc tạo mục kiến thức cần truy nguồn chặt.

Chọn nhà cung cấp cụ thể trong nhóm (1): chưa đủ căn cứ. Đề nghị thử hai nhà cung cấp trên cùng 20 truy vấn tiếng Việt thật của một website, so số kết quả dùng được và giá hiện hành, rồi mới chốt.

### Ước lượng ngân sách tương đối

Không có con số tiền. Về số lượt:
- Mỗi khoảng trống cần lấp: khoảng 1–3 lượt tìm, tải 3–5 trang, mỗi trang một lượt tách bằng AI (trần 5.000 đồng mỗi trang đang có).
- Một website có 10 khoảng trống mỗi tháng: khoảng 10–30 lượt tìm và 30–50 trang đọc mỗi tháng.
- Vì vậy **chi phí token để đọc trang nhiều khả năng lớn hơn phí tìm kiếm** **(suy đoán, cần đo)**. Trần cần đặt theo số trang đọc mỗi tháng cho mỗi website, không chỉ theo số lượt tìm.

---

## 9. Lộ trình theo giai đoạn nhỏ

Mỗi giai đoạn giao được độc lập và có giá trị riêng. Tên tệp mới là đề xuất. "Vùng dùng chung" gồm `BrainPage`, `BrainLibraryPage`, các view `brain-*`, `components/studio/*`, `seo-studio.css`: thay đổi ở đó cần PR riêng đã thống nhất trước.

### Giai đoạn 0: Dữ liệu nền (chỉ migration và Model)

- **Phạm vi:** các bảng và cột ở phần 7. Không đổi hành vi.
- **Tệp:** migration mới ngày `2026_11_20` trở đi; Model `BrainBuildRun`, `KnowledgeSuggestion`, `KnowledgeItemSource`, `BrainOverview`; bổ sung `fillable`/`casts` cho `KnowledgeItem`, `KnowledgeSource`.
- **Phụ thuộc Dev1:** rà migration và Model (PR riêng); thống nhất có dùng bảng phiên chạy chung của pipeline hay không.
- **Cách kiểm:** migrate, rollback, migrate lại sạch trên SQLite và MariaDB 10.6; toàn bộ test hiện có vẫn đạt.

### Giai đoạn 1: Xây kho từ website của mình + gợi ý khi viết bài (không tìm web)

- **Phạm vi:**
  1. Bộ tải trang an toàn, chỉ cho tên miền của chính website.
  2. Bộ trích phần chữ từ HTML.
  3. Dịch vụ điều phối lượt xây: chọn trang, tải, tách bằng `KnowledgeAiIngest`, phân tầng A/B/C bằng quy tắc, tự nhận tầng A theo mức tự chủ.
  4. Hộp gợi ý với ba loại không tốn token: `source`, `unverified_claim`, và `gap` (đọc từ `knowledge_gaps`).
  5. Khởi tạo một bước và hộp "Cần bạn xem".
- **Tệp mới (đề xuất):** `app/Services/Knowledge/Build/SafePageFetcher.php`, `ReadableTextExtractor.php`, `BrainBuildService.php`, `RiskTiering.php`; `app/Services/Knowledge/KnowledgeSuggestions.php`; một lệnh lịch `brain:build-tick`; một trang Filament mới cho khởi tạo và hộp "Cần bạn xem".
- **Tệp có sẵn bị đụng:** `KnowledgeAiIngest.php` (thêm tầng rủi ro và chủ đề vào đầu ra; nhận nguồn loại `url`), `routes/console.php` (thêm lịch), `workspace.blade.php` (thêm thẻ gợi ý; phối hợp với phiên đang xây không gian làm việc).
- **Phụ thuộc Dev1:** rà thay đổi lời nhắc trong `KnowledgeAiIngest`; đồng ý cho thêm một lệnh lịch tạm trước khi có AG-B10. **Không** cần sửa `AgentRunOrchestrator` ở giai đoạn này vì ba loại gợi ý lấy từ kết quả luật kiểm chất lượng đã lưu **(suy đoán: cần xác nhận kết quả luật được lưu ở dạng đọc lại được sau khi chạy)**.
- **Vùng dùng chung:** nếu đặt hộp "Cần bạn xem" vào trang Trí tuệ thì cần PR vùng dùng chung. Cách ít va chạm hơn: làm trang mới riêng, trang Trí tuệ chỉ thêm một đường dẫn.
- **Cách kiểm:**
  - Test đơn vị cho bộ tải: từ chối `localhost`, IP nội bộ, giao thức lạ, chuyển hướng sang tên miền khác, phản hồi quá lớn, loại nội dung không phải văn bản.
  - Test bộ trích chữ với vài tệp HTML mẫu (có menu, bảng, mã lệnh).
  - Test phân tầng: mục loại `price` luôn là C; mục từ website mình loại `fact` là A; Trí tuệ `must_cite` không tự nhận tầng B.
  - Test lượt xây với nhà cung cấp AI giả: dừng đúng khi chạm trần, chạy tiếp từ vị trí đã lưu, trang không đổi thì không gọi AI.
  - Test hoàn tác một lượt xây.
  - Mức bằng chứng sau giai đoạn này: 2. Để lên 3 cần chạy trên staging với AI thật và một website thật, đo: số mục rút được, tỉ lệ mục tự nhận bị người hoàn tác, chi phí thật.

### Giai đoạn 2: Tổng quan, hồ sơ nháp, hỏi đáp có trích dẫn, gợi ý từ bản sửa

- **Phạm vi:** tổng quan tự sinh; AI điền nháp Hồ sơ doanh nghiệp và Hồ sơ lĩnh vực (luôn là nháp, người lưu mới có hiệu lực); màn hình "Hỏi kho"; gợi ý loại `new_fact` và `outdated` từ bản sửa của người; gợi ý `gap` do người viết AI tự báo.
- **Tệp mới:** `app/Services/Knowledge/BrainOverviewService.php`, `KnowledgeQa.php`, `EditLearning.php`; lời nhắc mới cho tổng quan và hỏi đáp.
- **Tệp có sẵn bị đụng:** `AgentRunOrchestrator.php` (thêm trường "thông tin còn thiếu" vào đầu ra bước viết và ghi thành gợi ý) và lời nhắc bước viết.
- **Phụ thuộc Dev1:** **có, nặng hơn giai đoạn 1.** Sửa `AgentRunOrchestrator` là mã lõi; lời nhắc thuộc `resources/prompts` của Dev1. Nên tách phần này thành PR riêng do Dev1 làm hoặc rà. Phần tổng quan và hỏi đáp không phụ thuộc orchestrator, có thể giao trước.
- **Cách kiểm:** test rằng câu trả lời không có chú thích hợp lệ bị gắn cảnh báo; chú thích trỏ tới mục không có trong ngữ cảnh bị loại; câu hỏi không có mục liên quan trả về "kho chưa có"; gợi ý `outdated` chỉ sinh khi khớp được giá trị. Với AI thật: bộ 20 câu hỏi có đáp án biết trước để đo tỉ lệ trả lời đúng và tỉ lệ trả lời ngoài kho.

### Giai đoạn 3: Tìm web có kiểm soát

- **Phạm vi:** giao diện `SearchProvider` và một nhà cung cấp; tìm để lấp khoảng trống; ban đầu chỉ tìm trong các tên miền đã xác nhận trong Sổ nguồn; nguồn mới ngoài sổ chỉ ghi `suggested`; mọi mục từ nguồn ngoài là tầng B hoặc C.
- **Điều kiện trước:** bạn chốt nhà cung cấp và ngân sách (Q4, Q5); gia cố bộ tải cho tên miền bên ngoài (chuyển hướng, ghim IP, `robots.txt`); **nên có hàng đợi AG-B10**, vì tải nhiều trang bên ngoài theo lượt lịch 15 phút là quá chậm và dễ nghẽn.
- **Tệp mới:** `app/Contracts/SearchProvider.php`, `app/Services/Search/*`, cấu hình khóa trong `.env` (không đưa khóa vào kho mã; kho mã là công khai).
- **Phụ thuộc Dev1:** AG-B10; rà bảo mật bộ tải; trần chi phí chung.
- **Cách kiểm:** nhà cung cấp tìm kiếm giả trong test; test rằng URL ngoài danh sách tên miền bị loại ở phía mình dù nhà cung cấp trả về; thử thật 20 truy vấn tiếng Việt để đo chất lượng.

### Giai đoạn 4: Làm mới định kỳ và mở rộng mức tự chủ

- **Phạm vi:** kiểm băm hằng tuần cho website của mình, hằng tháng cho nguồn ngoài; sinh gợi ý `outdated` khi trang nguồn đổi; xem xét mở mức "Tự động" dựa trên số liệu thật; xem xét vectơ nếu số liệu hỏi đáp cho thấy tìm bằng FULLTEXT không đủ.
- **Phụ thuộc Dev1:** AG-B10.
- **Cách kiểm:** test trang đổi nội dung thì sinh gợi ý, trang không đổi thì không tốn token; báo cáo tỉ lệ hoàn tác mục tự nhận trong 30 ngày.

### Thứ tự và phụ thuộc tóm tắt

| Giai đoạn | Cần web search | Cần AG-B10 | Đụng mã lõi | Đụng vùng dùng chung |
|---|---|---|---|---|
| 0 | Không | Không | Không | Không |
| 1 | Không | Không (dùng lịch tạm) | Không (chỉ `KnowledgeAiIngest`) | Tránh được nếu làm trang mới |
| 2 | Không | Không | **Có** (orchestrator, lời nhắc) | Có thể |
| 3 | **Có** | Nên có | Không | Không |
| 4 | Tùy | **Có** | Không | Không |

---

## 10. Quyết định cần bạn chốt, và rủi ro

### 10.1. Quyết định cần bạn chốt

| # | Câu hỏi | Đề nghị của tài liệu |
|---|---|---|
| Q1 | Mức tự chủ mặc định của kho là gì? | **Cân bằng** |
| Q2 | Có cho phép **tự nhận không cần duyệt** với thông tin của chính doanh nghiệp lấy từ website của họ (tầng A) không? Đây là thay đổi so với quy tắc hiện tại "AI không bao giờ tự duyệt". | Có, kèm nhãn "tự nhận" và hoàn tác. Nếu không, mọi mục vẫn qua duyệt và phần tiết kiệm công sức giảm đáng kể. |
| Q3 | Giá và chính sách lấy từ website của chính doanh nghiệp: tự nhận hay luôn duyệt? | **Luôn duyệt** (tầng C) |
| Q4 | Có làm tìm web (giai đoạn 3) không, và khi nào? Câu này cũng là câu hỏi số 3 còn mở trong tài liệu kiến trúc. | Làm sau khi giai đoạn 1 có số liệu thật |
| Q5 | Nếu có: chọn nhóm nhà cung cấp nào, ngân sách mỗi website mỗi tháng là bao nhiêu? | API tìm kiếm riêng; ngân sách do bạn định sau khi kiểm giá |
| Q6 | Ai được duyệt mục do AI đề xuất và ai được bấm "Nhận" gợi ý? Hiện là admin và approver; bạn đã ghi "tính sau". | Giữ như hiện tại; biên tập viên bấm Nhận thì mục ở trạng thái chờ duyệt |
| Q7 | Số trang đọc và trần chi phí cho lượt xây đầu? | 30 trang, trần theo trang như đang có; chỉnh sau khi đo |
| Q8 | Gom mười tab của trang kho còn sáu, hay giữ trang cũ và thêm trang mới bên cạnh? Việc gom đụng vùng dùng chung. | Giai đoạn 1 làm trang mới bên cạnh; gom sau khi được thống nhất |
| Q9 | Có lưu phần chữ của trang **bên ngoài** không, và giữ bao lâu? | Lưu để trích dẫn nội bộ, không hiển thị lại nguyên văn dài; thời hạn do bạn định |
| Q10 | Có mở mức "Tự động" không? | Chưa; xem lại sau khi có tỉ lệ hoàn tác ở mức Cân bằng |
| Q11 | Mẫu sức khỏe và lĩnh vực rủi ro cao: khóa ở Thủ công cho nguồn ngoài? | Có |

### 10.2. Rủi ro

| Rủi ro | Mô tả | Cách giảm | Phần còn lại sau khi giảm |
|---|---|---|---|
| **AI bịa hoặc đọc sai** | Mục tự nhận có thể sai mà không ai xem | Đoạn nguyên văn bắt buộc khớp với nguồn; con số phải có trong đoạn gốc (đã có); tầng C không bao giờ tự nhận; hoàn tác theo lượt | Kiểm "có trong đoạn gốc" không bắt được lỗi **diễn giải sai ý**. Chưa có số liệu AI thật về tỉ lệ này. |
| **Kiến thức cũ** | Trang cũ trên website chứa thông tin đã hết đúng; hệ thống tự nhận | Hiển thị ngày tải và ngày xuất bản; giá và chính sách luôn duyệt; làm mới định kỳ; gợi ý `outdated` từ bản sửa của người | Trang không ghi ngày thì không biết cũ hay mới |
| **Bản quyền nội dung** | Lưu và dùng lại nội dung trang của bên khác | Mục kiến thức là dữ kiện được diễn đạt lại kèm dẫn nguồn, không chép đoạn dài; đoạn nguyên văn giữ ngắn, chỉ dùng nội bộ để kiểm; tôn trọng `robots.txt`; có thời hạn xóa văn bản thô | Tôi không phải luật sư. Giới hạn pháp lý cụ thể tại Việt Nam **cần người có chuyên môn pháp lý xem**, nhất là khi kho mã công khai và sản phẩm phục vụ nhiều khách hàng. |
| **SSRF và tải trang độc hại** | URL do AI hoặc kết quả tìm kiếm đưa ra trỏ vào mạng nội bộ | `UrlSafetyService` đã có; gia cố chuyển hướng và ghim IP (4.4); giai đoạn 1 chỉ tải tên miền của chính website | Phần gia cố chưa viết, chưa thử |
| **Chèn lệnh qua nội dung web** | Trang chứa văn bản ra lệnh cho AI | Đóng khung có mã ngẫu nhiên, lược đồ đầu ra chặt, AI không có công cụ (đã có trong `KnowledgeAiIngest`); nội dung web không bao giờ đi vào lời nhắc hệ thống | Không có biện pháp nào chặn tuyệt đối; hậu quả được giới hạn vì đầu ra chỉ là mục đề xuất có cấu trúc |
| **Chi phí vượt dự tính** | Website lớn, trang dài, làm mới thường xuyên | Trần theo trang, theo lượt, theo ngày; chỉ gọi AI khi băm đổi; dừng và báo khi chạm trần | Ước lượng chi phí trong tài liệu chưa được đo |
| **Hộp gợi ý thành nơi chứa rác** | Quá nhiều gợi ý, người dùng bỏ qua tất cả | Gộp gợi ý trùng; sắp theo rủi ro và số lần lặp; gợi ý cũ quá 30 ngày tự chuyển sang "để sau"; giai đoạn đầu chỉ bật ba loại ít nhiễu | Cần đo tỉ lệ nhận/bỏ thật để chỉnh ngưỡng |
| **Tự nhận làm lệch nguyên tắc an toàn** | "Tự nhận" bị mở rộng dần sang nội dung rủi ro | Tầng C xác định bằng quy tắc cố định trong mã, không bằng AI và không bằng cấu hình; có test khóa lại | Phân loại `type` của mục vẫn do AI gán; mục giá bị AI gán nhầm thành `fact` sẽ lọt. Giảm bằng quy tắc phụ: mục có con số kèm đơn vị tiền tệ luôn là C. |
| **Va chạm với việc của người khác** | Trang Trí tuệ, không gian làm việc bài viết, orchestrator đều có người đang sửa | Giai đoạn 1 tránh vùng dùng chung và mã lõi; phần đụng tới thì tách PR | |
| **Chất lượng tìm trong kho** | FULLTEXT không hiểu từ đồng nghĩa; hỏi đáp và nạp kiến thức vào bài có thể bỏ sót | Chấp nhận ở giai đoạn đầu; đo; quyết định vectơ sau | FULLTEXT trên MariaDB thật chưa được kiểm (đã ghi trong tài liệu bàn giao) |

### 10.3. Những điều tài liệu này chưa kiểm

- Chưa chạy bất kỳ phần nào với AI thật; mọi con số về chi phí, số mục, thời gian là ước lượng.
- Chưa thử khai thác các điểm yếu nêu ở 4.4; đó là nhận định từ việc đọc mã.
- Chưa kiểm giá và tính năng lọc tên miền của các nhà cung cấp tìm kiếm.
- Chưa xác nhận kết quả các luật kiểm chất lượng (`EVID01`, `FactRule`, `KnowledgeFigureRule`) được lưu ở dạng đủ chi tiết để sinh gợi ý mà không phải chạy lại.
- So sánh với NotebookLM là mô tả chung về kiểu trải nghiệm, không phải đối chiếu tính năng hiện hành.

