# Kỹ năng chuyên gia theo vai trò (đội AI SEO, trọng tâm y khoa, sức khỏe, dinh dưỡng)

> v1.0 · 21/11/2026 · Nhánh `claude/chuyen-gia-vai-tro-2cdd52` (cắt từ `feat/M09/ag-s28/dev2/quan-ly-seo-dieu-phoi`). **Chỉ commit cục bộ, chưa push, chưa PR.**
> Mức kiểm chứng theo AGENTS.md: (1) đã viết code/dữ liệu → (2) test giả lập đạt → (3) staging → (4) người thật nghiệm thu. **Mọi mục dưới đây mới ở mức (1)–(2).** Chưa chạy với model thật, chưa có chuyên gia y khoa hay chuyên viên dinh dưỡng rà soát.
> **Nguyên tắc nội dung:** tất cả là hướng dẫn biên tập, **không chứa số liệu y khoa** (ngưỡng, liều, khẩu phần); số liệu luôn lấy từ nguồn được duyệt trong đầu vào (có test regex chặn số kèm đơn vị y khoa trong playbook, mẫu hồ sơ và 14 bảng tiêu chí).
> **Pháp lý (theo yêu cầu người dùng, nhắc lại 21/11):** chỉ là **cảnh báo mềm để người làm biết**, không chặn, không đổi luồng.

## 1. Bốn kênh đưa kỹ năng tới model, và kênh nào đang hoạt động thật

| Kênh | Hoạt động thật? | Ghi chú |
|---|---|---|
| **A. Playbook nạp bởi `usePlaybook`/`STEP_PLAYBOOK`** | **Có, nhưng chỉ cho 9 playbook cũ** (bảng 2) | Playbook mới **chưa** nằm trong danh sách này; thêm vào cần sửa orchestrator (Dev1), xem mục 5 |
| **B. Hồ sơ huấn luyện đã lưu** (persona, quy tắc, bài học, kỹ năng riêng, tài liệu) | **Có, cho 7 vai trò** (`AgentProfiles::APPLIED_AT`) | Đây là đường **ngay lập tức** để đưa tri thức mới vào: nút **Nạp mẫu chuyên gia** (ExpertTemplates) hoặc tab **Huấn luyện → Nạp từ file** với CSV ở `docs/seo-agent/mau-huan-luyen/`. Chỉ phần đã Lưu mới vào prompt |
| **C. Playbook chỉ hiển thị ở tab Kỹ năng/Vai trò** | Không có tác dụng lên prompt | Hướng dẫn tham khảo, có lineage |
| **D. Bảng tiêu chí CSV mẫu** | Là dữ liệu để nạp qua kênh B | 14 file, mỗi file ≥ 5 tiêu chí, đọc trực tiếp không tốn AI |

**Vai trò nhận hồ sơ huấn luyện thật:** Người viết, Chiến lược, Biên tập, Họa viên, Quản lý SEO, Người nghiên cứu (điều phối kho), Người phân tích tệp. **Chưa có bước nào nhận hồ sơ:** Kiểm chứng viên y khoa, Người kiểm ảnh, Người lập kế hoạch, Nghiên cứu từ khóa, Đối thủ, Giám sát, Audit. Với 7 vai trò này, nạp CSV/mẫu vẫn **lưu được nhưng chưa có tác dụng** cho đến khi có bước gọi AI nhận hồ sơ.

## 2. Playbook đang được nạp THẬT vào prompt (trước đợt này, không đổi)

| Playbook | Bước | Điều kiện |
|---|---|---|
| `brief/content-brief` | Lập dàn ý (`brief`) | luôn |
| `writer/article-draft`, `writer/article-layout` | Viết (`write`) | luôn |
| `writer/health-nutrition-writing` | Viết/sửa | chủ đề rủi ro cao hoặc kho bắt buộc nguồn |
| `editor/qa-review` | Chấm và sửa (`grade`, `repair`) | luôn |
| `editor/ymyl-medical-review` | Biên tập viên đọc bài | rủi ro vừa/cao |
| `editor/citation-support` | Kiểm trích dẫn | khi bật |
| `designer/image-design` | Thiết kế ảnh | luôn |
| `manager/redo-coordination` | Đọc lời nhắn làm lại | khi giao làm lại |

Các playbook cũ còn lại (`researcher/evidence-hunting`, `trend-research`, `planner/*`, `analyst/*`, `auditor/*`, `monitor/*`, `designer/health-infographic`, `manager/run-supervision`...) **chưa nạp vào bước nào** (hướng dẫn).

## 3. Đợt này đã nạp gì (17 playbook mới, trạng thái `draft`, **chưa nạp vào bước nào**)

| Playbook | Vai trò dùng | Học từ | Giấy phép / mức tin cậy |
|---|---|---|---|
| `researcher/medical-literature-reading` | researcher | Khung GRADE bốn mức, Cochrane MECIR C74 (mức chắc chắn phải mang sang tóm tắt cho người đọc) | Chuyển thể ý, không chép. **Trung bình** (đã đọc qua tóm tắt; chưa đọc toàn văn Handbook) |
| `researcher/source-vetting` | researcher | Kiểm chứng ngang (nguồn/bản gốc/đối chiếu), thông lệ công khai | Chuyển thể ý. Trung bình |
| `researcher/health-search-intent` | keyword | Soạn mới; bổ sung `keyword-cluster` | internal. Thấp-trung bình (chưa đo thật) |
| `editor/evidence-wording` | editor, fact_checker | GRADE + Cochrane plain-language | Chuyển thể ý. Trung bình |
| `editor/claim-evidence-matrix` | fact_checker, editor | Soạn mới | internal. Thấp-trung bình |
| `writer/nutrition-communication` | writer | Thông lệ truyền thông dinh dưỡng của các hội nghề nghiệp (chưa gắn một văn bản cụ thể) | Chuyển thể ý. **Thấp-trung bình**, chưa có chuyên viên dinh dưỡng rà |
| `writer/senior-readability` | writer | CDC Clear Communication Index (20 mục, 0/1), NIA/NLM Senior Friendly Web Sites (2001, một phần đã cũ) | Tài liệu liên bang Hoa Kỳ công khai; chuyển thể ý. Trung bình |
| `writer/supplement-claims-awareness` | writer | Luật Quảng cáo 2012, NĐ 181/2013/NĐ-CP (sửa đổi), NĐ 15/2018/NĐ-CP Điều 26-27 | **Thấp-trung bình**: chỉ đọc qua trang tóm tắt thuvienphapluat.vn, **chưa đối chiếu toàn văn hiện hành**. Chỉ cảnh báo mềm; pháp lý do người phụ trách công ty xác nhận |
| `strategist/answer-first-structure` | strategist | **claude-seo/seo-geo 2.5.0 (MIT)**: Google coi tối ưu cho AI "vẫn là SEO", không cần llms.txt hay băm nhỏ nội dung | MIT. Bỏ toàn bộ số liệu bên thứ ba chưa kiểm chứng. Trung bình |
| `manager/ymyl-risk-triage` | manager | **claude-seo/seo 2.5.0** `references/eeat-framework.md` (Trust quan trọng nhất; sức khỏe là nhóm đòi hỏi cao nhất) | MIT. Bỏ trọng số nội bộ. Trung bình |
| `designer/accessible-health-visual` | designer | W3C WCAG 2.2 (mức AA), CDC Clear Communication Index | Chuyển thể ý. **Chưa** kiểm bằng công cụ tương phản |
| `designer/health-image-safety-qa` | image_qa | Soạn mới; bổ sung `designer/image-qa` | internal. Danh sách nhắc người duyệt, chưa có AI nhìn ảnh |
| `monitor/ymyl-freshness-review` | monitor | **claude-seo/seo-drift 2.5.0 (MIT)** ý "mốc nền → phát hiện thay đổi" | MIT. Bỏ số liệu "ba tháng" của bên thứ ba |
| `auditor/audit-ymyl-trust` | auditor | **claude-seo/seo 2.5.0** (eeat-framework: Trustworthiness) | MIT. Không khẳng định điểm E-E-A-T |
| `analyst/competitor-claims-reading` | competitor | **claude-seo/seo-sxo 2.5.0 (MIT)** | MIT. Thêm danh sách "chiêu không bắt chước" |
| `analyst/source-document-triage` | file_analyst | Soạn mới | internal |
| `planner/health-seasonal-planning` | planner | Soạn mới; bổ sung `content-calendar` | internal |

Cập nhật khai báo: mỗi vai trò trong `resources/agents/roles/*.php` đã thêm kỹ năng mới vào `skills` (strategist thêm `origin` `claude-seo/seo-geo`). Test `tests/Unit/PlaybookSkillsTest` đối chiếu `source_version` với `claude-seo` cục bộ khi có thư mục.

## 4. Bảng kỹ năng chuyên gia cần có, theo vai trò

Ký hiệu: ✅ đã có và đang nạp · 🟡 đã có nhưng chỉ là hướng dẫn (chưa nạp vào bước) · 🆕 mới thêm đợt này (chưa nạp) · ❌ thiếu (xem đề xuất).

| Vai trò | Kỹ năng người làm nghề thật sự cần | Hiện có | Còn thiếu / cơ bản |
|---|---|---|---|
| **Quản lý SEO** | Hiểu mục đích, giao việc, đọc phạm vi "chỉ…"; phân bậc rủi ro YMYL; dừng đúng lúc; báo cáo thật thà | ✅ `redo-coordination`; 🟡 `seo-orchestrator`, `run-supervision`, `weekly-review`; 🆕 `ymyl-risk-triage` | Phân bậc rủi ro có trong hệ thống bằng cấu hình chủ đề, nhưng chưa nạp hướng dẫn quyết định (cần nối) |
| **Chiến lược** | Ý định tìm kiếm; dàn ý trả lời thẳng; thực thể rõ; liên kết nội bộ; schema; biết chủ đề nào cần chuyên gia | ✅ `content-brief`; 🟡 `content-plan`, `internal-linking`, `schema-plan`; 🆕 `answer-first-structure` | Dàn ý theo SERP thật chưa có dữ liệu; mới là hướng dẫn |
| **Người viết** | Viết cho người không chuyên; nêu mức bằng chứng; dinh dưỡng không phán xét; dễ đọc cho người cao tuổi; biết ranh giới thực phẩm chức năng | ✅ `article-draft`, `article-layout`, `health-nutrition-writing`; 🆕 `nutrition-communication`, `senior-readability`, `supplement-claims-awareness` | Ba kỹ năng mới chưa nạp. Hiện đưa vào qua hồ sơ huấn luyện (kênh B) |
| **Biên tập** | Soát an toàn, giọng văn đúng mức bằng chứng, nguồn, quảng cáo ẩn | ✅ `qa-review`, `ymyl-medical-review`, `citation-support`; 🟡 `eeat-check`; 🆕 `evidence-wording`, `claim-evidence-matrix` | Hai kỹ năng mới chưa nạp |
| **Họa viên** | Một ảnh một thông điệp, chữ ngắn, tương phản, không hình gây sợ | ✅ `image-design`; 🟡 `image-brief`, `health-infographic`, `image-qa`; 🆕 `accessible-health-visual` | Chưa có AI nhìn ảnh để kiểm đúng khái niệm |
| **Người nghiên cứu** | Tìm nguồn theo thang bậc; đọc y văn; kiểm nguồn; xử lý kết quả 0 | 🟡 `evidence-hunting`, `trend-research`; 🆕 `medical-literature-reading`, `source-vetting` | `evidence-hunting` **cũng chưa nạp vào prompt**; chỉ hồ sơ huấn luyện đi vào bước điều phối kho |
| **Nghiên cứu từ khóa** | Ý định, cụm, trùng ý định, từ khóa nên tránh | 🟡 `keyword-cluster`, `cannibalization-check`, `local-keywords`; 🆕 `health-search-intent` | Chưa có bước AI nhận hồ sơ |
| **Đối thủ** | Đọc SERP, so sánh công bằng, không bắt chước chiêu quá đà | 🟡 `serp-gap`, `backlink-gap`; 🆕 `competitor-claims-reading` | Chưa có dữ liệu SERP/backlink thật |
| **Giám sát** | Mốc nền, phát hiện lệch, rà bài y tế theo rủi ro | 🟡 `drift-baseline`, `refresh-trigger`; 🆕 `ymyl-freshness-review` | Chưa nối Search Console |
| **Audit** | Kỹ thuật, on-page, schema, tín hiệu tin cậy | 🟡 5 playbook audit; 🆕 `audit-ymyl-trust` | Chưa có bước AI; mã quét chạy riêng |
| **Lập kế hoạch** | Lịch theo mùa, thời gian duyệt, không chạy theo tin nóng | 🟡 `content-calendar`; 🆕 `health-seasonal-planning` | Chạy ở trang Kế hoạch, chưa ghi nhật ký phiên |
| **Phân tích tệp** | Nhận diện cột; phân loại tài liệu y khoa | 🟡 `file-intake`; 🆕 `source-document-triage` | Chỉ nhận diện cột là có hồ sơ huấn luyện thật; phân loại tài liệu chưa nối |
| **Kiểm ảnh** | Chữ và dấu, ngụ ý điều trị, sơ đồ đúng khái niệm | 🟡 `image-qa`; 🆕 `health-image-safety-qa` | Mới chạy bằng luật; chưa có AI nhìn ảnh; chữ Việt phải do người kiểm |
| **Kiểm chứng viên y khoa** (đề xuất) | Ma trận khẳng định-nguồn, nhóm người, rủi ro | 🟡 dùng lại `ymyl-medical-review`, `citation-support`; 🆕 `claim-evidence-matrix`, `evidence-wording` | Chưa là bước riêng; chỉ tách khi có chuyên gia y khoa nghiệm thu |

## 5. Đề xuất nối bước cho Dev1 (KHÔNG sửa orchestrator trong đợt này)

Mỗi mục là một lần gọi `usePlaybook(role, key)` giống `health-nutrition-writing` ở `AgentRunOrchestrator.php` quanh dòng 2004 (nạp thêm vào prompt, chỉ bản draft cho lượt thủ công), kèm một test cho thấy nội dung playbook xuất hiện trong prompt:

1. **Viết:** khi `risk_level` cao hoặc `must_cite`, nạp thêm `writer/nutrition-communication` và `writer/senior-readability` (ghép vào `domain_skill`, chú ý trần độ dài prompt).
2. **Biên tập viên đọc bài (dòng ~1177):** nạp `editor/evidence-wording` cùng `ymyl-medical-review` khi rủi ro vừa/cao.
3. **Nghiên cứu:** nạp `researcher/medical-literature-reading` và `source-vetting` vào bước tìm nguồn/điều phối kho (nay chỉ hồ sơ huấn luyện vào).
4. **Quản lý SEO:** nạp `manager/ymyl-risk-triage` vào bước đọc lời nhắn.
5. **Họa viên:** nạp `designer/accessible-health-visual` cạnh `image-design`.
6. **Tách vai trò Kiểm chứng viên:** chỉ khi có người có chuyên môn nghiệm thu; khi đó `claim-evidence-matrix` làm bước riêng.
7. Các vai trò chưa có bước AI (từ khóa, đối thủ, giám sát, audit, lập kế hoạch, kiểm ảnh) cần bước gọi `AiJson::withProfile()` trước khi nạp hồ sơ có tác dụng.

Mức tăng token khi nạp thêm chưa đo; cần cân bằng với trần 14.000 ký tự (viết) và 6.000 (JSON).

## 6. Bảng tiêu chí CSV mẫu để nạp ngay

14 file ở `docs/seo-agent/mau-huan-luyen/` (tên theo khóa vai trò, gạch nối thay gạch dưới): `manager`, `strategist`, `writer`, `editor`, `designer`, `researcher`, `keyword`, `competitor`, `monitor`, `auditor`, `planner`, `file-analyst`, `image-qa`, `fact-checker`. Cách dùng: **Agent Studio → vai trò → Huấn luyện → Nạp từ file → chọn CSV → xem lại → Lưu**. Cột: Loại, Tiêu chí, Quy tắc cụ thể, Vì sao, Mức (Bắt buộc/Nên/Gợi ý). Đọc trực tiếp, không gọi AI. Vì mỗi file có dòng "bài học", chúng cũng đi vào mục bài học của vai trò. Chỉ vai trò thuộc danh sách 7 ở mục 1 mới có tác dụng lên prompt.

## 7. Nguồn đã tra trong đợt này và độ tin cậy

| Nguồn | Dùng để | Mức tin cậy của lần tra |
|---|---|---|
| claude-seo 2.5.0 (cục bộ, MIT): `seo-geo`, `seo/references/eeat-framework.md`, `seo-geo/references/google-ai-optimization-guide.md` | Cấu trúc trả lời trước; Trust/YMYL; tín hiệu tin cậy | Cao (đọc trực tiếp bản cục bộ). Giữ đúng lưu ý của chính repo: số liệu bên thứ ba chưa kiểm chứng nên không dùng |
| Google Search Quality Rater Guidelines | Khái niệm YMYL, E-E-A-T, Trust là trung tâm; E-E-A-T không phải điểm xếp hạng trực tiếp | Trung bình: kết quả tra chủ yếu là bài bình luận bên thứ ba; **chưa đọc trực tiếp PDF** của Google |
| Cochrane Handbook / MECIR C74, GRADE | Bốn mức chắc chắn; mức chắc chắn phải mang sang tóm tắt cho người đọc | Trung bình (đọc qua trích đoạn tra cứu); **chưa đọc hướng dẫn tóm tắt ngôn ngữ đơn giản của Cochrane** |
| CDC Clear Communication Index | Tiêu chí truyền thông rõ ràng | Trung bình (mô tả 20 mục, 0/1; chưa đọc danh sách đầy đủ từng mục) |
| NIA/NLM "Making Your Web Site Senior Friendly" (2001) | Nguyên tắc trang web cho người cao tuổi | Thấp-trung bình: tài liệu cũ; chưa đối chiếu WCAG 2.2 từng tiêu chí |
| Luật Quảng cáo, NĐ 181/2013/NĐ-CP, NĐ 15/2018/NĐ-CP (thuvienphapluat.vn) | Cảnh báo mềm về quảng cáo thực phẩm chức năng | **Thấp-trung bình**: chỉ trang hỏi đáp/tóm tắt; kết quả tra tự cảnh báo có chỗ dẫn chiếu nghị định xử phạt chưa chắc chính xác. Người phụ trách pháp lý phải xác nhận trước khi dựa vào |

**Đã làm / chưa làm trong nghiên cứu:** chưa tra riêng Bộ Y tế, Viện Dinh dưỡng, WHO ngoài nhận xét thang nguồn đã có trong dự án; chưa đọc NHS/DISCERN; phần claude-seo **chưa học**: `seo-hreflang`, `seo-maps`, `seo-ecommerce`, `seo-programmatic`, `seo-agentic`, `seo-dataforseo` (xem `SKILL_MAP.md`; không ưu tiên cho website nội dung sức khỏe tiếng Việt).

## 8. Kiểm thử đã làm

- `tests/Feature/Agent/ExpertSkillsPackTest.php` (5 test, mức 2): 17 playbook mới đọc được bằng `PlaybookLoader`, đủ lineage, vai trò nào khai kỹ năng nào cũng có file; lineage claude-seo đúng phiên bản 2.5.0 và MIT; 14 CSV được `parseTemplateCsv` đọc ra ≥ 5 tiêu chí; playbook, CSV và mẫu hồ sơ không chứa số kèm đơn vị y khoa; ExpertTemplates đủ 14 vai trò và nằm trong `PERSONA_MAX`/`INSTRUCTIONS_MAX`.
- Test này **không** chứng minh model làm theo các kỹ năng; không chứng minh nội dung đúng y khoa.
