# Gói rà soát cho Dev1

Ngày: 11/11/2026. Nhánh: `feat/m09/ag-s27/dev2/article-workspace`, nền `d8b4ff4` (đầu `tmp/m09-rebased`). Người soạn: Dev2. **Chưa push, chưa mở PR.** Tài liệu này giúp Dev1 chọn cách rà và biết chỗ nào cần mắt kỹ nhất.

## 1. Tóm tắt

- 45 commit từ `d8b4ff4`, 246 tệp, +19.564 / −282 dòng. Phần lớn là mã mới (dịch vụ, trang, test); phần sửa mã sẵn có nhỏ và tập trung (mục 4).
- **Không đụng**: `app/Services/Pilot`, `resources/prompts`, `app/Integrations` (Magento/WordPress), `ReviewWorkflow`, `app/Services/Publishing`. Đã kiểm bằng `git diff --name-only d8b4ff4 HEAD -- <thư mục>` (rỗng).
- Hướng chung: chuỗi làm bài (Từ khóa → Kế hoạch → Nghiên cứu và nguồn → Viết → Kiểm và duyệt → Xuất bản → Đo lường), bớt rào cản, AI tự chuẩn bị và tự đảm bảo, chế độ "Toàn quyền" cho đội AI. Thiết kế: `THIET_KE_PIPELINE_THEO_LOAI_VIEC.md`, `THIET_KE_KHO_TRI_TUE_TU_DONG.md`, `CHINH_SACH_TU_DONG_HOA.md`, `DANH_GIA_TRAI_NGHIEM_VA_KE_HOACH_CAI_THIEN.md`.

## 2. Mức bằng chứng (nói thẳng)

| | Đã làm | Chưa làm |
|---|---|---|
| Test | Chạy cuối (sau đợt gọn giao diện): SQLite **1405 đạt, 0 lỗi, 2 bỏ qua**; MariaDB 10.6 **1400 đạt, 5 lỗi, 2 bỏ qua**. 5 lỗi đều là `PilotRunTest` của Dev1 (`QueryException`, lỗi cũ, issue #6); chênh 5 test so với SQLite đúng bằng số này, không có lỗi mới | MariaDB chỉ chạy một lần trên mã này; cần chạy lại nếu có commit mới |
| Giao diện | Đi qua bằng tay trên bản xem thử cục bộ (writer, approver, admin) | Không có website thật |
| **AI thật, API ngoài** | **Chưa lần nào**: chưa gọi model thật, Tavily, DataForSEO, API ảnh; mọi test dùng AI giả và `Http::fake` | Đo chất lượng, token, chi phí thật |

Chi phí mỗi ảnh (1.000 ₫), chi phí/100 từ khóa, trần chi phí: **ước tính cấu hình, chưa đo**.

## 3. Migration (8; PR riêng theo quy ước)

Kiểm trên MariaDB 10.6 (container tạm): chạy lên cả 28 migration, rollback 5 migration cuối (`2026_11_24` → `2026_11_20`) rồi chạy lại cả 5, đều thành công, không lỗi.

| Tệp | Nội dung | Rủi ro | Kiểm |
|---|---|---|---|
| `2026_11_20_000001_add_job_type_to_agent_runs` | `agent_runs`: `job_type` (mặc định `article_write`), `parent_run_id`, `input_refs`, `output_ref`, `tokens_budget`; **`article_id` cho phép rỗng** | Đổi cột `article_id` NOT NULL → NULL trên bảng lõi. `down()` **xóa các phiên không gắn bài** trước khi trả NOT NULL | Up/down/up trên SQLite và MariaDB |
| `2026_11_21_000001_add_origin_and_risk_tier_to_knowledge` | `knowledge_sources`: `origin`, `fetched_at`, `final_url`, `http_status`; `knowledge_items`: `risk_tier`, `review_mode` | Thấp (cột mới nullable) | Up/down/up MariaDB |
| `2026_11_22_000001_create_agent_profiles_tables` | `agent_profiles`, `agent_profile_versions`, `agent_lessons`, `agent_documents` (hồ sơ agent) | Bảng mới | Up/down/up MariaDB |
| `2026_11_23_000001_add_volume_source_to_keywords` | `keywords`: `volume_source`, `metrics_at`, `competition`, `cpc_vnd` | Thấp | Up/down/up MariaDB |
| `2026_11_24_000001_add_guidance_to_articles` | `articles.guidance` (text, nullable) | Thấp | Up/down/up MariaDB |
| `2026_11_25_000001_add_checkpoints_to_campaigns` | `campaigns.checkpoints` (json, nullable): điểm dừng chờ người `plan/outline/draft/publish` | Thấp | Up/down trên SQLite; chưa chạy lại MariaDB |
| `2026_11_26_000001_add_models_to_ai_providers` | `ai_providers`: danh sách model bật cho từng tài khoản (hiển thị/chọn) | Thấp, không đổi định tuyến | Chưa chạy lại MariaDB |
| `2026_11_27_000001_allow_paused_publication_status` | **`publications.status` từ enum sang chuỗi 20 ký tự** để thêm `paused` và `awaiting_confirm` | **CAO NHẤT: đổi kiểu cột bảng xuất bản.** `down()` chuyển lượt paused/chờ xác nhận sang `failed` rồi trả enum | Up/down/up đã thử trên MariaDB; vẫn cần Dev1 xem lại kiểu cột |

Đợt 11/11: đã chạy up → rollback 8 → up trên MariaDB 10.6, đều thành công; cả bộ test MariaDB 1444 qua, chỉ 5 `PilotRunTest` đỏ (lỗi cũ của Dev1). Rollback thêm: 27 → 26 → 25.

Thứ tự rollback: 24 → 23 → 22 → 21 → 20.

## 4. Mã sẵn có bị sửa (chỗ cần soi kỹ nhất)

| Tệp | Thay đổi | Câu hỏi cho Dev1 |
|---|---|---|
| `Services/Agent/Run/AgentRunOrchestrator.php` (+209/−12) | Bước `research` tách khỏi `write`; kiểm ngân sách trước viết; nhận dàn ý có sẵn; **tự gắn Trí tuệ** (`BrainAutoAttach`); **tự chuẩn bị kiến thức** (đọc website công ty, rồi tìm web nếu có khóa) khi kho trống; cờ chuyên gia sau khi viết (thay cho cổng chặn); bước ảnh sau khi lưu bản; khối hồ sơ vai trò và `guidance` vào lời nhắc | Thứ tự bước và `seq` (bước ảnh ghi `seq` riêng); lỗi ở các bước phụ có bị nuốt đúng chỗ không (đều bọc try/catch, không làm hỏng phiên); `allowWithoutKnowledge` giữ chữ ký nhưng **không còn tác dụng** |
| `Services/Ai/AbstractLlmProvider.php` (+136/−3) | `PartialRefreshInterface` (sinh lại meta, sửa mục); khối `guidanceBlock`, hồ sơ vai trò | Prompt mới có kiểm schema đầu ra đủ nghiêm không; chống tiêm lệnh ở khối `guidance`/hồ sơ |
| `Services/Ai/MockAiProvider`, `DemoScriptedAiProvider` | Cài `PartialRefreshInterface` | — |
| `Services/Agent/Run/CampaignRunner.php` (+109/−7) | Mở khóa chế độ `autonomous`; **tự duyệt có điều kiện** qua `ReviewWorkflow::approve` bằng người dùng hệ thống `ai-auto@system.local` (role approver, mật khẩu ngẫu nhiên, tạo một lần); gọi Autopilot trước khi chọn bài; `tickCore()` bọc `tick()` | Đây là điểm nhạy nhất về nghiệp vụ (mục 5 và 6). `ReviewWorkflow` **không bị sửa**, chỉ được gọi |
| `Services/Content/Quality/ContentQualityChecker`, `QualityRubric`, `Rules/SourceRule.php` | Luật mới `SRC01` (nguồn AI nêu mà không khớp kho/Sổ nguồn/tên miền website → P1, không chặn) | Cách so khớp tên/tên miền; tỉ lệ cờ cần đo trước khi nâng mức |
| `Services/Knowledge/KnowledgeContext`, `KnowledgeRetriever`, `KnowledgeAiIngest`, `Models/KnowledgeItem` | Loại mục `product` (tối đa 2 chỗ riêng khi viết), `ingest` nhận loại `product` | Truy xuất FULLTEXT trên MariaDB thật chưa kiểm với dữ liệu thật |
| `Models/AgentRun`, `AiModelRoute` | Cột/cast mới; route `keyword_suggest`, `keyword_cluster`, `meta_regen`, `section_repair` | — |
| `Services/Agent/Roles/RoleRegistry.php` | `roleName()` trả tên tùy chỉnh khi có hồ sơ; `STEP_ROLE` thêm `images` | Truy vấn thêm mỗi lần gọi tên vai (có cache trong request) |
| `Filament/Support/Review/ReviewActions.php` (+4), `Schedule/RescheduleAction.php` (+1) | Bộ chọn ngày giờ: `dd/mm/yyyy HH:mm`, bước 5 phút, mặc định 9 giờ sáng mai, không chọn ngày đã qua | Chỉ là cấu hình `DateTimePicker`, nhưng `ReviewActions` là mã của Dev1 |
| `Filament/Resources/ArticleResource.php` (+186/−61) | Writer được giao đội AI (trước: chỉ approver); giao hàng loạt; cột "Phiên gần nhất" đổi cách hiển thị | Quyền: duyệt/xếp lịch/đăng **vẫn chỉ approver** (không đổi) |
| `resources/playbooks/risk/topics.php` | Thêm thuật ngữ y tế vào nhóm rủi ro cao (đường huyết, HbA1c, insulin, cholesterol…) | Danh sách từ khóa rủi ro nên do ai chốt |

## 4b. Bổ sung đợt 11/11 (cấu trúc thông tin và điểm dừng)

- `AgentRunOrchestrator::run(..., ?array $checkpoints = null)`: điểm dừng `outline` kết thúc phiên `held` (step `checkpoint`), không gọi bước viết. Diff lõi ~18 dòng.
- `PublishArticleJob`: bỏ qua lượt `paused` / `awaiting_confirm` (6 dòng); `Publication` thêm hằng/hàm trạng thái (~18 dòng). Lệnh quét chỉ nhặt `scheduled`. **Cần Dev1 soi kỹ nhất: đây là đường đăng thật.**
- `CampaignRunner`: điểm dừng `draft` chặn tự duyệt; `AutopilotRunner`: điểm dừng `plan` chỉ ghi đề xuất chờ nhận (`pendingProposals`, `acceptProposals`).
- Các trang dùng chung bị sửa để gộp menu: `BrainLibraryPage`, `BrainBuildPage`, `WebsiteOnboardingPage` (vào cụm Kho trí tuệ), `AiUsagePage` (thêm tab Cấu hình AI), `AgentConnection` bị xóa (URL cũ chuyển hướng), `AgentTeam::canAccess` nay cho biên tập viên (để dùng tab Kế hoạch; từng tab tự kiểm quyền).
- Quan hệ: chiến dịch là cha, bài là con (`articles.campaign_id`), bài không chiến dịch là "Bài lẻ".

## 5. Thay đổi hành vi cần Dev1 đồng ý (không chỉ là mã)

1. **Không còn cổng chặn trước bước viết** với bài rủi ro cao mà kho trống. Thay bằng: tự chuẩn bị kiến thức, viết, rồi gắn nhãn "chờ chuyên gia xem lại". (Yêu cầu của chủ dự án: ít rào cản.)
2. **Chế độ Toàn quyền tự duyệt** bài thường khi: không còn P0, không có quyết định `high_risk_expert_review`/`brain_must_cite_review`, hạng rủi ro không cao. Bài sức khỏe/rủi ro cao **không bao giờ tự duyệt**. Xuất bản vẫn ở bậc T1 (chỉ nháp CMS), không có mã tự đăng công khai mới.
3. **Tự gắn Trí tuệ** vào website khi trùng cụm hai từ liền nhau với chủ đề bài (tối đa 2/bài, 3/website), ghi nhật ký, có Hoàn tác.
4. **Mục kiến thức từ nguồn tin cậy tự được nhận**, kể cả lĩnh vực sức khỏe, nếu có đoạn nguyên văn hợp lệ. Nguồn "tin cậy" do **luật** (danh sách tên miền `config/trusted_sources.php` + Sổ nguồn); loại `price|policy|myth|forbidden` không tự nhận. Đây đổi nguyên tắc cũ "chỉ người xác nhận nguồn uy tín".
5. **Hồ sơ doanh nghiệp tự điền** từ website (chỉ điền trường trống, có Hoàn tác).
6. **Writer giao được đội AI.**
7. **Autopilot** (`autopilot:tick`, lịch 30 phút) cho chiến dịch Toàn quyền: khảo sát từ khóa (tối đa 1 lần/24 giờ/website) và thêm bài vào kế hoạch. Chạy đồng bộ theo nhịp lịch, **chưa chạy nền** (AG-B10).
8. **Hồ sơ agent do admin sửa đi vào lời nhắc** (persona, quy tắc, bài học, tài liệu, tối đa 14.000 ký tự).

## 6. Điểm bảo mật cần mắt Dev1

- **`SafePageFetcher` chế độ tải tên miền ngoài** (`fetchExternal`): chống SSRF bằng `UrlSafetyService::validateUrl` ở mọi bước, tắt tự chuyển hướng (tối đa 3, kiểm lại từng đích), 2 MB, timeout 10 giây, chỉ HTML/text, tôn trọng `robots.txt`. Đã test chặn IP nội bộ và chuyển hướng ra IP nội bộ; **chưa thử khai thác thật**, chưa ghim IP đã kiểm khi gửi yêu cầu (nhận định từ thiết kế trước: nên có).
- **Chèn lệnh qua nội dung web/hồ sơ/chỉ dẫn**: nội dung web đóng khung có nonce và chỉ rút mục có đoạn nguyên văn; `guidance` và hồ sơ agent chặn ký tự điều khiển, cắt độ dài, kèm câu "không được ghi đè quy tắc an toàn" trong prompt. Không chặn tuyệt đối.
- **Khóa/đăng nhập dịch vụ ngoài** chỉ qua `.env` (`TAVILY_API_KEY`, `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD`, khóa ảnh); lỗi được ẩn khóa. Chưa có khóa thì tính năng hiện "chưa cấu hình", không chạy giả lập im lặng ở production.
- **Người dùng hệ thống `ai-auto@system.local`** có role approver: nên xem có cần role riêng, và cách audit log ghi tự duyệt (nhãn "Tự duyệt bởi đội AI (chế độ Toàn quyền)").

## 7. Gợi ý cách rà (ưu tiên)

1. **Trước hết**: 5 migration + `AgentRun` (`774a82c`) vì mọi thứ khác dựa vào. Kiểm `article_id` nullable ảnh hưởng ai (đã rà đọc các trang phiên, Chi phí AI, tổng quan: null-safe).
2. **Lõi đội AI**: `AgentRunOrchestrator`, `AbstractLlmProvider`, `CampaignRunner` (mục 4 và 5).
3. **Bảo mật**: `SafePageFetcher` ngoài, tự nhận mục kiến thức, tự duyệt.
4. Còn lại là trang/dịch vụ mới (ít rủi ro với mã sẵn có): Pipeline trực quan, Agent Studio, tìm nguồn, số liệu lượt tìm, tạo ảnh, kế hoạch bằng luật, onboarding, báo cáo phiên, dải chuỗi.

**Về tách PR:** các commit đan xen (ví dụ `AgentRunOrchestrator` bị sửa ở G4, V1, W3, W4, G5), nên cherry-pick theo chủ đề sẽ phát sinh xung đột. Nếu Dev1 muốn PR nhỏ, Dev2 sẽ dựng nhánh theo từng cụm từ trạng thái cuối (không phải từng commit): (a) migration + `agent_runs`; (b) lõi đội AI + chất lượng; (c) kho tri thức (xây từ website, tìm web, gợi ý); (d) từ khóa (AI, lượt tìm); (e) giao diện (bài, Pipeline, Agent Studio, dải chuỗi); (f) chế độ Toàn quyền + Autopilot; (g) ảnh + làm mới một phần. Chưa làm vì cần Dev1 chốt cách chia.

## 8. Cách chạy lại bằng tay

```bash
# Nhánh worktree: /home/zunp/addp-autoseo/wt/ag-s27 (image addp-autoseo-app:latest; vendor mount từ checkout chính)
docker run --rm -u $(id -u):$(id -g) -e APP_KEY=base64:ew+FzpBIk32ejUTVFtEbHNDY0Xa9g7POv3Hlh7FRuMc= \
  -v $PWD:/var/www/html -v /home/zunp/addp-autoseo/addp-autoseo/vendor:/var/www/html/vendor:ro \
  -w /var/www/html addp-autoseo-app:latest php artisan test
```

MariaDB: một container `mariadb:10.6` tạm (tmpfs) trên mạng riêng, đặt `DB_CONNECTION=mysql DB_HOST=… DB_DATABASE=seo_test DB_USERNAME=root DB_PASSWORD=test`. **Không** đặt `STUDIO_KEYWORDS_REAL=true` trong `.env` khi chạy test (làm 4 test `ContentPlanPageTest` dùng kho từ khóa thật và đỏ).

## 9. Tắt nhanh nếu có sự cố

| Việc | Cách tắt |
|---|---|
| Autopilot | Gỡ `Schedule::command('autopilot:tick')` trong `routes/console.php`, hoặc chuyển chiến dịch về "Duyệt trước" |
| Toàn quyền tự duyệt | Chuyển chiến dịch về "Duyệt trước" (mode `cautious`/`balanced`); không còn tự duyệt |
| Tạo ảnh AI | `AGENT_IMAGES_ENABLED=false` |
| Tìm web | Không đặt `TAVILY_API_KEY` |
| Số liệu lượt tìm | Không đặt `DATAFORSEO_*` |
| Tự gắn Trí tuệ | Hoàn tác từng Trí tuệ ở trang bài; hiện chưa có công tắc tắt toàn bộ (nên thêm nếu Dev1 muốn) |

## 10. Câu hỏi để Dev1 trả lời

1. Chấp nhận `agent_runs.article_id` nullable (mục 3) hay muốn bảng riêng cho phiên không gắn bài (phương án P2 trong thiết kế)?
2. Tự duyệt trong Toàn quyền: dùng người dùng hệ thống role approver có ổn, hay cần cơ chế riêng trong `ReviewWorkflow`?
3. Mục kiến thức từ nguồn tin cậy tự nhận cho cả lĩnh vực sức khỏe: Dev1 đồng ý với nguyên tắc này không?
4. Chạy nền thật (AG-B10, hàng đợi): lịch dự kiến, vì Autopilot, xây kho, tìm web, tạo ảnh hiện đều chạy đồng bộ.
5. Quyết định chung về tách PR (mục 7).
