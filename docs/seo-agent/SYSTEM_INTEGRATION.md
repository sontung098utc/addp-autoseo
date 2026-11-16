# Khớp thiết kế đội AI với hệ thống hiện có

> v1.0 · 12/11/2026 · Đọc từ mã tại nhánh `feat/m09/ag-s27/dev2/article-workspace`. Thiết kế vai trò: [ROLE_WORKFLOWS.md](ROLE_WORKFLOWS.md). Kỹ năng và nguồn: [SKILL_MAP.md](SKILL_MAP.md).
> Nhãn: **[CÓ]** đã có mã · **[GIỮ]** dùng nguyên · **[DATA]** chỉ cần sửa dữ liệu playbook/role/job-type · **[CODE]** cần đổi code · **[SCHEMA]** cần migration. Mọi cải tiến đề xuất đều ghi rõ cần gì.

## 1. Mục tiêu: giá trị cho đội SEO người

Đội AI không thay đội người; nó làm phần lặp lại (nghiên cứu, nháp, kiểm luật, lập kế hoạch) và **để người quyết** phần có rủi ro. Ba thứ phải đúng thì mới có giá trị thực:
1. Người thấy được **ai làm gì, vì sao bị trả, còn thiếu dữ liệu gì** (nhật ký bước, "Đội AI hôm nay", bản tin).
2. Mọi thứ ra ngoài (đăng bài, sửa bài đã đăng, liên hệ PR) **có điểm dừng người**.
3. Số đo thật (Search Console) đóng vòng, nếu không thì không biết đội AI có ích không.

## 2. Bảng khớp theo thành phần

### 2.1 Chiến dịch (Campaign, CampaignRunner, Autopilot)
| | |
|---|---|
| Hiện có **[CÓ]** | `Campaign` (mode `cautious/balanced/autonomous`, `daily_limit`, `daily_cost_cap_vnd`, `daily_draft_cap`, `checkpoints` plan/outline/draft/publish, `brain_ids`, `source`), `CampaignWait` (`no_keywords`, `kb_gap`...), `CampaignRunner::tick` (dừng khẩn cấp, website hoạt động, giới hạn, trần chi phí, chọn bài `planned` kế tiếp, tự duyệt Toàn quyền), `AutopilotRunner` (giữ hàng chờ đủ đầy bằng khảo sát + `ContentPlanner`) |
| Vai trò đọc | strategist/writer/editor qua `AgentRunOrchestrator` (đã nhận `campaignId`, `checkpoints`). manager [TK] đọc `campaign_waits` và chi phí |
| Ghi | `agent_runs` (`campaign_id`, `trust_mode`), `articles.campaign_id` |
| Sự kiện kích hoạt | nhịp lịch (`agent:tick`) hoặc nút "chạy một nhịp"; mỗi nhịp một bài |
| Trạng thái | `Campaign.status`: planning/draft → active ↔ paused → completed/archived |
| Khoảng trống | (a) Chiến dịch chỉ có **một loại việc** là viết bài mới; chưa có chiến dịch "làm lại bài" hay "audit" **[CODE]**. (b) `Campaign.settings` (JSON) và `target_keywords` có sẵn để chứa cấu hình loại chiến dịch mà không cần migration **[CODE nhỏ]**. (c) Chọn bài kế tiếp là luật (`nextPlanned`); manager chỉ nên khuyến nghị trước **[CODE]** |
| Giá trị cho người | Mở chiến dịch với một nút, thấy hàng chờ, lý do dừng (`blocked_gap`, `cost_cap`...) bằng lời |

### 2.2 Bài viết (Article, ArticleVersion, workspace, review)
| | |
|---|---|
| Hiện có **[CÓ]** | `articles.workflow_status`: `planned → drafting → needs_review → approved/rejected → scheduled → published`; `kind` (new/refresh), `origin` (plan, keyword, audit, ...), `approved_version_id`; `article_versions.review_status`, `cms_draft_status`; `ReviewWorkflow` (submit/approve/reject/schedule) chặn duyệt khi còn P0 |
| Vai trò đọc/ghi | writer ghi `article_versions`; editor ghi báo cáo luật; strategist ghi dàn ý vào `agent_run_steps.data` |
| Sự kiện | giao bài → phiên chạy → `ready_for_review` → người duyệt (hoặc tự duyệt có điều kiện ở Toàn quyền) |
| Khoảng trống | (a) **Lý do bị trả** hiện chỉ là mã luật + ghi chú từ chối của người; chưa có `reason_code` có cấu trúc của đội AI **[CODE nhỏ, dùng `agent_run_steps.data`]**. (b) Cột `origin` đã có chỗ cho `gsc` (đề xuất từ monitor); chưa có nơi tạo **[CODE]**. (c) Chưa lưu `schema_type`, `internal_links` đề xuất cho bài **[DATA/CODE]**: có thể để trong `agent_run_steps.data` trước, chưa cần cột |
| Giá trị | Người thấy bài, bản so sánh, lỗi cụ thể, và vì sao đội AI sửa |

### 2.3 Lịch (kế hoạch nội dung, lịch đăng)
| | |
|---|---|
| Hiện có **[CÓ]** | `ContentPlanner::propose/create` (luật: bỏ từ khóa đã có bài, bỏ lệch chủ đề, xếp ưu tiên theo lượt tìm, khoảng trống, độ phủ cụm; nguồn volume ghi rõ csv/tay/dataforseo/gsc/mock), `PlannedArticleCreator`, `Publication` (`scheduled`, `publishing`, `published`, `failed`, `needs_reconcile`, `held`, `paused`, `awaiting_confirm`) và `Lịch xuất bản` (`PublicationResource::scheduleUrl()`; `/admin/lich-tuan` chuyển hướng) |
| Vai trò | strategist (kế hoạch) làm bằng **luật**, không qua AI; manager [TK] chỉ khuyến nghị |
| Khoảng trống | (a) **Lịch nội dung theo tuần/tháng gắn cụm chủ đề** (đăng đều theo cụm) chưa có **[CODE]**. (b) Chưa có "mật độ đăng tối đa theo website" chung cho chiến dịch và lịch **[CODE]**. (c) Kế hoạch tự cập nhật theo số liệu Search Console chưa có **[DỮ-LIỆU + CODE]** |
| Giá trị | Người thấy kế hoạch kèm lý do xếp ưu tiên; đổi lịch có giải thích |

### 2.4 Kết nối web (website connection, CMS handoff, Publishing)
| | |
|---|---|
| Hiện có **[CÓ]** | `Website` + `WebsiteConnectionCheck`, `PublisherManager` (WordPress, Magento trong `app/Integrations`), `DraftCapable`, `CmsDraftHandoff::push` (chỉ chiến dịch Cân bằng, không đẩy khi còn P0, không đẩy chủ đề rủi ro cao, không khi dừng khẩn cấp), `PublishArticleJob`, `ReconcilePublicationJob`, `EmergencyStopService`, `PublicationHold` |
| Vai trò | `system` bàn giao; editor quyết bài đạt luật; người đăng |
| Khoảng trống | (a) Đẩy **JSON-LD** và **liên kết nội bộ** lên CMS: hiện chỉ đẩy nội dung bài; chưa kiểm connector có nhận trường schema hay không **[CẦN KIỂM + CODE]**. (b) Đọc lại **URL đã đăng** và trạng thái lập chỉ mục để đóng vòng đo (cần `publications.published_url` hợp lệ + Search Console) **[CODE]**. (c) Sửa bài đã đăng (làm lại) phải qua người, đã có nguyên tắc nhưng chưa có test riêng cho đường refresh→CMS **[CẦN KIỂM]** |
| Giá trị | Không bao giờ đăng thẳng khi không được phép; nháp CMS là bước người xem được |

### 2.5 Từ khóa (Keywords, cluster, DataForSEO)
| | |
|---|---|
| Hiện có **[CÓ]** | `keywords` (status, source), `keyword_clusters`, `keyword_gaps` (nguồn csv/gsc/crawl/tay), `KeywordResearchService` (khảo sát AI), `KeywordAiResolver`, `KeywordMetricsEnricher` + `DataForSeoKeywordMetricsProvider` (khối lượng, ước chi phí, có `mock`), nhập CSV |
| Vai trò | keyword: **[MÃ-NGOÀI]** nút "Khảo sát thị trường (AI)" và "Gom cụm" ghi phiên (job-type `market_research`) |
| Khoảng trống | (a) DataForSEO hiện **chỉ khối lượng từ khóa**, không có SERP/backlink **[CODE nếu chọn dùng]**. (b) Kiểm **trùng ý định với bài có sẵn** chưa có mã (playbook `cannibalization-check` mới là hướng dẫn cho AI/luật) **[CODE nhỏ: so khớp từ khóa/tiêu đề trong `ContentPlanner`]**. (c) Từ khóa địa phương cần cờ loại hình doanh nghiệp trong `brand_profiles` **[SCHEMA hoặc dùng JSON sẵn có]**. (d) Gom cụm theo SERP chưa làm được (không SERP) |
| Giá trị | Từ khóa không tranh nhau; kế hoạch có lý do |

### 2.6 Kho trí tuệ (Brain, Knowledge, KB, hồ sơ)
| | |
|---|---|
| Hiện có **[CÓ]** | `Brain`/`BrainProfile`/`BrainSource`, `KnowledgeItem` (tầng A/B/C, duyệt), `KnowledgeRetriever`, `KnowledgeGaps` + `knowledge_gaps`, `KnowledgeFreshness`, `KnowledgeConflicts`, `BrainBuildService` (kho từ website, Tavily), `BrandProfile` (+ `BrandCompliance`), luật `KB01`, `EVID01`, `SRC01` |
| Vai trò | research của writer nạp kiến thức; editor `RiskClassifier` + `needs_expert`; manager [TK] đọc `knowledge_gaps` để đề xuất bổ sung |
| Khoảng trống | (a) Tự điền **loại hình doanh nghiệp / khu vực phục vụ / sản phẩm** để bật kỹ năng local/ecommerce: cần chuẩn hóa trong `brand_profiles` hoặc `settings` **[CODE nhỏ]**. (b) "Bài học" từ lý do bị trả vào `AgentLesson` **[CODE nhỏ]**. (c) E-E-A-T cần **kinh nghiệm thật** (ảnh, ca thật, tên tác giả) - cần nơi người đưa tư liệu riêng vào kho trước khi viết, hiện kho có nhưng chưa có "mục tiêu tác giả" **[CODE/UX]** |
| Giá trị | Bài có căn cứ thật thay vì AI tự nghĩ; đây là điểm khác biệt so với nội dung AI đại trà |

### 2.7 Dashboard "Đội AI hôm nay", bản tin, Agent Studio, Pipeline
| | |
|---|---|
| Hiện có **[CÓ]** | `AgentTodayWidget` (việc đang chạy, chờ người, cần chuyên gia, bị chặn, chi phí - chỉ đếm dữ liệu thật), `DigestPage`/`DigestQueries`, `AgentRunResource` (nhật ký từng bước, vai trò, kỹ năng, chi phí), Agent Studio (`AgentProfiles`, `AgentLesson`, model theo vai trò `RoleAssignments`), `JobTypeRegistry` + `PipelineBlueprint`/`PipelineVisual` (sơ đồ; bước `planned` không vẽ), `RoleRegistry` (readiness suy từ mã) |
| Khoảng trống | (a) **Phiên chạy của job-type khác** (audit, từ khóa, làm lại) cần ghi vào `agent_runs`/`agent_run_steps` cùng định dạng để dashboard thấy; hiện `market_research` có ghi phiên, `site_audit` chạy bằng lệnh **[CODE]**. (b) Lý do bị trả (`reason_code`) hiện lên dashboard "Bị chặn/Bị trả" **[CODE nhỏ]**. (c) Widget "Hiệu quả" khi có Search Console **[DỮ-LIỆU + CODE]** |
| Giá trị | Người quản lý biết sáng nay đội AI làm gì, tốn bao nhiêu, kẹt ở đâu |

### 2.7b Ảnh và media (vai trò Họa viên `designer`) — thêm ở giai đoạn 3
| | |
|---|---|
| Hiện có **[CÓ]** | `ArticleImageStep` → `ArticleImageService` (cổng, kế hoạch vị trí, trần chi phí 10% ngân sách phiên, nhãn AI, chú thích trong bài), `ImageGeneratorResolver` (bản gán `agent_role_assignments(designer)` → route cũ `image_gen` → ảnh giả lập chỉ ở local), `FallbackImageGenerator` (model chính + dự phòng), `MediaService`/`media_assets`/`article_version_media`, `ImageSelector` (chọn ảnh thư viện trước) |
| Vai trò | `designer` ghi `agent_run_steps(stage=images, role=designer)`; tắt được (`RoleSwitches`) → bước `skipped` (`role_off`) |
| Loại model | Cột `ai_providers.models` nay chấp nhận chuỗi tên (dữ liệu cũ, loại suy từ tên) hoặc `{id, kind}`; `AiModelCatalog::kindOf/available($kind)`; `RoleAssignments::saveModel` từ chối gán sai loại ở máy chủ. **Không cần migration** (cột JSON có sẵn) |
| Dữ liệu cũ | Migration `2026_11_13_000001_copy_image_route_to_designer_assignment` sao chép route `image_gen` sang bản gán của designer (idempotent, không xóa route). **Chưa chạy trên preview**; không chạy thì route cũ vẫn hoạt động (resolver tự lùi về route) nhưng vai trò Họa viên hiện "chưa gán" |
| Khoảng trống | (a) Playbook `designer/image-brief`/`image-qa` mới là nháp, bước ảnh vẫn dùng `ImagePromptBuilder` luật cố định **[CODE]**. (b) Kiểm sau khi tạo (`image-qa`) chưa có mã **[CODE]**. (c) Tạo ảnh chưa chạy với model ảnh thật ở quy mô bài; chi phí ảnh dùng ước tính cấu hình **[CẦN KIỂM THẬT]**. (d) Ảnh cho trang không phải bài (danh mục, sản phẩm) chưa có |

### 2.8 Trust / risk / chấm luật
| | |
|---|---|
| Hiện có **[CÓ]** | `RiskClassifier` (hạng rủi ro chủ đề), `TrustGate` (ngưỡng: mẫu ≥20, hoàn thành ≥0,80, sửa ≥0,60; Mức 2 khóa cứng), `RoleMatrix`, `QualityRubric` + 14 luật (`LEN01`...`FAQ01`), `AiPaddingFilter`, `RunPolicy` (số lần thử lại, vòng sửa, ngân sách) |
| Khoảng trống | (a) Luật mới cho **schema** (`SCHEMA01` cú pháp/khớp nội dung) và **liên kết nội bộ theo cụm** (`LINK02`) **[CODE + test]**; luật `LINK01` hiện chỉ kiểm liên kết thuộc website. (b) **Chưa có phép đo chất lượng bài thật**: bộ vàng `EvalHarness`/`GoldenSet` đã có khung, cần bộ mẫu tiếng Việt thật (AG-K09) để playbook rời `draft` **[DATA + người]** |

## 3. Sự kiện và chuyển trạng thái (bản đồ kích hoạt)

| Sự kiện | Hiện có | Bước kế tiếp | Mã |
|---|---|---|---|
| Nhịp chiến dịch (lịch) | `CampaignRunner::tick` | chọn bài `planned` → phiên viết | [CÓ] |
| Hàng chờ kế hoạch vơi (< ngưỡng) | `AutopilotRunner` | khảo sát từ khóa → `ContentPlanner` | [CÓ] |
| Dàn ý xong + điểm dừng `outline` | `OutlineHold` | chờ người | [CÓ] |
| Bản nháp đạt P0 | `ready_for_review` / `CmsDraftHandoff` | hàng chờ duyệt / nháp CMS (Cân bằng) | [CÓ] |
| Người duyệt | `ReviewWorkflow::approve` | `approved` → lên lịch → `PublishArticleJob` | [CÓ] |
| Audit hoàn tất | `AuditSuggestionService` | `audit_suggestions` → người chấp nhận → bài `refresh` | [CÓ] |
| Bài refresh xong | `ArticleRefreshRunner` | người duyệt so trước/sau | [CÓ] |
| Editor trả bài có lý do | — | `return` → vai trò đúng, ≤2 vòng | [CODE] |
| Dàn ý bị phản biện | — | `outline_review` | [CODE] |
| Số liệu Search Console về | — | `monitor` → `refresh_requests` → manager | [CODE + DỮ-LIỆU] |
| Bài đã đăng đủ tuổi | — | đo lại sau N ngày | [CODE + DỮ-LIỆU] |
| Cuối tuần/tháng | — | `manager/weekly-review` | [CODE] |
| Lý do trả lặp ≥3 lần | — | đề xuất `AgentLesson` | [CODE nhỏ] |

## 4. Việc cần làm, xếp ưu tiên

Tác động: ■■■ cao · ■■ vừa · ■ thấp. Độ khó: D1 dễ (≤1 ngày) · D2 vừa · D3 khó (nhiều ngày hoặc có phụ thuộc ngoài).

### 4.1 Chỉ là dữ liệu / tài liệu (đã làm trong đợt này hoặc làm ngay được)
| Việc | Trạng thái |
|---|---|
| 9 playbook mới (nháp) + ghi công trong `NOTICE.md` | **Đã làm** |
| Cập nhật `resources/agents/roles/*.php` (mục tiêu, `on_fail`, kỹ năng ý định, ghi chú sẵn sàng) | **Đã làm** |
| Bước `planned` trong job-type `site_audit` (`rootcause`, `action_plan`) và `article_refresh` (`trigger`) | **Đã làm**; `article_write` **không** thêm bước `planned` vì test `JobTypeRegistryTest` buộc danh sách bước = bước chạy thật (nếu muốn vẽ bước dự kiến phải sửa test) |
| Hiệu chỉnh ngưỡng, bộ mẫu tiếng Việt, chuyển playbook từ `draft` sang `active` | Cần người + bộ mẫu (AG-K09) |

### 4.2 Cần code (top 10, theo tác động / độ khó)
| # | Việc | Tác động | Khó | Ghi chú |
|---|---|---|---|---|
| 1 | **Phong bì `return/deliver`** trong `agent_run_steps.data` + hiển thị lý do bị trả ở trang phiên | ■■■ | D1-D2 | Không cần migration; nền cho mọi thứ sau |
| 2 | Nối `editor/eeat-check` vào bước `grade` (định tính, không điểm số) và để editor trả `must_fix` bằng lời cho vòng `repair` | ■■■ | D2 | Cần thử model thật + bộ mẫu |
| 3 | **Phản biện dàn ý** (`outline_review`) editor→strategist ≤2 vòng, kèm điểm dừng `outline` đã có | ■■■ | D2 | Thêm bước vào orchestrator + test; cập nhật `STEP_ROLE` |
| 4 | **Kết nối Search Console** (OAuth read-only, bảng số liệu theo trang/ngày) | ■■■ | D3 | **[SCHEMA]**; mở khóa monitor, KPI, làm lại bài, đo hiệu quả; task 5.1 |
| 5 | Kiểm **trùng ý định** với bài có sẵn trong `ContentPlanner` và Autopilot | ■■ | D1-D2 | Luật so khớp, 0 token |
| 6 | **Liên kết nội bộ theo cụm** bằng mã: mở rộng `InternalLinkSuggester` theo `keyword_clusters`; luật `LINK02` | ■■ | D2 | Chỉ đề xuất; không sửa bài đã đăng |
| 7 | **Ghi phiên cho audit/làm lại/khảo sát** vào `agent_runs` cùng định dạng để dashboard thấy mọi việc | ■■ | D2 | Dùng `JobRunRecorder` đã có |
| 8 | Nguồn **SERP thật** (chọn nhà cung cấp, khóa, ngân sách) và bước `serp` trước `brief` | ■■ | D3 | **[SCHEMA]** lưu SERP; phụ thuộc quyết định chủ dự án |
| 9 | **Schema markup**: `schema_type` từ strategist, sinh JSON-LD, luật `SCHEMA01`, đẩy lên CMS | ■■ | D3 | Cần kiểm connector WordPress/Magento nhận trường nào |
| 10 | **Manager khuyến nghị** (`weekly-review`, kế hoạch hành động) hiển thị ở dashboard; chưa thay luật chọn bài | ■■ | D2 | Bắt đầu chỉ-đọc; không tự đổi cài đặt |

Việc thấp hơn: loại chiến dịch "làm lại"/"audit" trong `Campaign.settings` (D2); `AgentLesson` tự đề xuất từ lý do trả (D1); loại hình doanh nghiệp trong hồ sơ để bật kỹ năng local/ecommerce (D1-D2); Core Web Vitals qua CrUX/PageSpeed (D2-D3); backlink gap (phụ thuộc nguồn dữ liệu, D3); GBP (D3).

## 5. Rủi ro và điều **không** làm

- Không tạo trang hàng loạt theo khuôn (programmatic) và không so sánh đối thủ tự động: rủi ro chính sách và pháp lý.
- Không hứa thứ hạng, lưu lượng, điểm "sức khỏe SEO".
- Không đẩy bài sức khỏe/tài chính/pháp lý lên CMS khi kho chưa có kiến thức đã duyệt (đã chặn trong `CmsDraftHandoff` và guard `high_risk_no_knowledge`).
- Không thêm vai trò thứ 9; mọi kỹ năng mới gắn vào vai trò sẵn có (ROLE_WORKFLOWS mục 3.9).
- Bản nháp playbook chưa `active`: chúng là **tài liệu dẫn đường**, `isRunnable()` vẫn false cho tới khi qua bộ mẫu tiếng Việt.
