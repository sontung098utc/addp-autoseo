# KẾ HOẠCH CHIA VIỆC — ĐỘI AI SEO TỰ ĐỘNG (`M09`): ĐƯỜNG MỎNG TRƯỚC, SONG SONG, DEV2 DẪN

> Bản 2/2 · **v0.3** · 07/11/2026 · Đi kèm `AI_AUTOMATION_DESIGN_ANALYSIS.md` v0.3 (nguyên tắc tự động, thang tin cậy, bảng tự phục hồi, Playbook) và mẫu `mockups/auto-seo-ui-preview.html`.
> **Chưa triển khai, chưa tạo nhánh, chưa sửa `TASKS.md`** (khối đề xuất ở §9). Giờ là **ước lượng thô của người thiết kế**, chưa Dev nào estimate; **không** cộng vào 432h (+64,8h dự phòng) của kế hoạch gốc.
> Giả định (G1–G4 ở bản 1): thang tin cậy, mặc định **T1 = tự đẩy CMS dạng nháp**; đường mỏng trên **một blog WordPress**; lõi điều phối trong **Laravel**; tự đăng (T2+) cần **văn bản chính sách** của bạn.

---

## 0. Cơ sở hiện tại (`origin/develop` @ `7e9c2c0`)
| Có sẵn | Hệ quả |
|---|---|
| UI Dev2 đã merge; 0.5, 3.4, 3.5, 1.2, 1.3, 2.1, 2.2 "Hoàn thành" (tự đánh dấu, chưa nghiệm thu thật) | Mọi thứ là **mở rộng** |
| `ContentQualityChecker` + 9 rule + `QualityThresholds` | Nền của rubric và **bộ chấm** cho vòng tự sửa |
| `ContentPipelineService` chạy **một lượt**; `GeminiAiProvider` **rơi về Mock khi lỗi** | Cần vòng sửa bọc ngoài; **bỏ fallback Mock** là điều kiện bật tự động |
| Crawler 1.1; autopublish 3.6 + `EmergencyStopService` (lưu trong **Cache**); WordPress/Magento connector; `UrlSafetyService` | Dùng lại; `AG-B08` bền hóa dừng khẩn cấp (**issue #9** đã gửi) |
| **Chưa có:** `agent_*`, router đa model, ngân sách/ngắt mạch, nhật ký run, hồ sơ doanh nghiệp, nguồn được duyệt, chính sách tin cậy, **khả năng gỡ bài**, GSC (5.1), PR-C | Nhóm `B`, `A`, `K` gần như mới |
| Dev1 còn **4.1 Pilot (16h)** + **4.2 (12h)** hạn 09/12; Dev2 còn **5.1, 5.4, 6.1, 6.4** | **Rủi ro công suất** → chia lát, chỉ cam kết lát đầu |

---

## 1. Nguyên tắc chia việc
1. **Cải thiện, không đập đi xây lại:** mỗi task nêu *mở rộng file nào / thêm file mới*. Không thay `ContentPipelineService`, `PublisherManager`, Jobs, `AiProviderInterface` (chỉ thêm), `ContentQualityChecker` (chỉ mở rộng).
2. **Đường mỏng trước, đào sâu sau:** Lát 2 phải chạy được **từ chọn chủ đề đến bản nháp trên WordPress mà không cần người**, dù playbook còn đơn giản.
3. **An toàn là điều kiện của tự động:** ngân sách, ngắt mạch, nhật ký run, không-Mock-im-lặng, dừng khẩn cấp **đi trước** bất kỳ chạy tự động nào.
4. **Seam trước, nối sau:** giao diện dựng trên interface (Nhập tay → Cục bộ → Từ xa).
5. **Song song an toàn:** cùng đợt, tập file rời nhau; file chung có một chủ (§5).
6. **PR nhỏ** (≤ ~250 dòng logic), squash merge `develop`, Dev kia review chéo, không commit thẳng `main/develop`.
7. **Trung thực:** 4 mức bằng chứng; thiếu môi trường ghi `Chưa chạy`/`Bị chặn tích hợp`; không nút giả; không `0` thay "chưa có dữ liệu"; chip *AI thật / AI giả lập*.
8. **Nghiệm thu tự động hóa bằng số liệu**, không bằng cảm nhận (§7).
9. **Ghi công** playbook học từ claude-seo (`lineage`, `NOTICE`; MIT; `seo-flow` CC BY 4.0). Không đổi trạng thái WBS gốc.

---

## 2. Mã task và đặt tên
- Milestone **`M09` — "Đội AI SEO tự động"**. Mã `AG-<nhóm><nn>`:

| Nhóm | Ý nghĩa | Chủ yếu |
|---|---|---|
| `F` | Nền tảng chung (contract, xác minh, ADR, nguồn dữ liệu) | Hai bên |
| `K` | Kiến thức: danh mục học, Playbook, Rubric, bộ đánh giá, hồ sơ, hạng rủi ro | **Dev2** (prompt/AI nghiệp vụ theo `AGENTS.md`) |
| `A` | **Tự động hóa**: vòng tự sửa, tự phục hồi, tìm thêm, giám sát viên, chính sách tin cậy, gỡ bài | Dev1 + Dev2 |
| `U` | Giao diện Filament | **Dev2** |
| `B` | Backend: migration, router, ngân sách, orchestrator, thông báo | **Dev1** |

- Nhánh: `<loại>/<Mxx>/<mã>/<dev>/<slug>` — vd `feat/m09/ag-a01/dev2/repair-loop`, `feat/m09/ag-b10/dev1/orchestrator`.
- PR: `[AG-A01] Vòng Viết–Chấm–Sửa dùng kết quả rubric`.

**Ánh xạ mã đã có (không làm trùng):** `K03/K07` ↔ `3.5`, `6.1` (gộp phạm vi 6.1) · `K04/U05` ↔ `1.1`, `1.3` · `K06` ↔ `2.1` · `K10` ↔ `5.4` · `F04/K08` ↔ `5.1` · `B01/B03` ↔ `ARCH-02` · `B06` ↔ `ARCH-03`, `6.5` · `B09/A04` ↔ `ARCH-05/06` · `B11` ↔ `6.3` (thông báo; n8n tùy chọn) · `A06/A07` ↔ mở rộng `1.2/1.4` · chiến dịch (bảng) ↔ PR-C (UI dùng tạm `topic_cluster`).

---

## 3. Bốn lát cắt và các đợt song song

```
LÁT 0 — NỀN (song song, vài ngày)
  Dev2: F01 contract · F02 xác minh develop · F04 nguồn dữ liệu SEO VN · K01 danh mục học + xác minh quy tắc
  Dev1: F03 ADR (lõi Laravel, vai trò n8n, giấy phép) · B00 job audit · B08 bền hóa dừng khẩn cấp

LÁT 1 — KHUNG TỰ ĐỘNG & AN TOÀN  (chưa cần nội dung hay)
  Dev1: B01+B02 migration · B03 RunRecorder · B06 AiModelRouter (bỏ Mock im lặng) · B09 ngân sách + ngắt mạch
        B10 Orchestrator xác định (state machine, checkpoint, retry, đỗ việc) · A02 bảng tự phục hồi · B11 thông báo ngoại lệ/bản tin
  Dev2: K02 định dạng Playbook · K03 rubric dạng dữ liệu · U00 menu · U01 nhãn · U02 Cách hoạt động · U03 seam
        U08 Hoạt động AI · U19 Bản tin · U15 nối DB
  ⇒ Hệ thống có thể chạy một chuỗi bước tự động, ghi nhật ký, có ngân sách, tự đỗ/thử lại, báo ngoại lệ

LÁT 2 — ĐƯỜNG MỎNG TRÊN 1 WEBSITE WORDPRESS (T1: đẩy CMS dạng NHÁP)
  Dev2: K04 audit · K05 brief · K07 QA+rào chắn · K11 hồ sơ doanh nghiệp · K12 hạng rủi ro + nguồn duyệt · K09 bộ đánh giá VN
        A01 vòng Viết→Chấm→Sửa · A03 tìm thêm trong nguồn duyệt · U05 Audit thật · U09 Tích hợp AI
  Dev1: A05a chính sách tin cậy (T1)
  ⇒ Một lần chạy tự động: audit → chủ đề → brief → viết → chấm-sửa → nháp WordPress → bản tin. Đo tỉ lệ tự hoàn tất

LÁT 3 — NÂNG TIN CẬY & MỞ RỘNG (chỉ sau khi số liệu Lát 2 đạt ngưỡng)
  Dev1: A04 giám sát viên LLM · A05b T2 + kiểm mẫu · A07 gỡ bài Magento · B04 API vào · B05 Gateway/n8n
  Dev2: A06 gỡ bài WordPress · K06 cụm từ khóa · K08 chiến lược · K10 đối thủ · U04 nhập báo cáo · U06abc · U07 Vai trò & Kỹ năng
        U10abc sửa theo đoạn · U11 Chiến dịch & mức tự động · U12 ngoại lệ/ghi đè hàng loạt · U13 · U14 kịch bản · U16 · U17 Pipeline map · U18 chỉ số · U20 Chính sách tin cậy
```

| Mốc | Sau | Người dùng/hệ thống làm được gì (thật) |
|---|---|---|
| **M9-0** | Lát 0 | Hợp đồng dữ liệu, nguồn dữ liệu được chọn, quy tắc SEO được xác minh, dừng khẩn cấp bền |
| **M9-A** | Lát 1 | Khung chạy tự động có ngân sách, nhật ký, đỗ việc, thông báo; bản tin hằng ngày; giao diện hiểu hệ thống |
| **M9-B** | Lát 2 | **Tự động hoàn tất một bài từ chủ đề đến nháp WordPress**, tự sửa theo rubric, bám hồ sơ doanh nghiệp, đo được tỉ lệ tự hoàn tất |
| **M9-C** | Lát 3 | Tự đăng nhóm rủi ro thấp (T2) có kiểm mẫu và tự gỡ — **chỉ khi có văn bản chính sách của bạn** |

---

## 4. Danh mục task
Cột: **Chủ/Review · Phụ thuộc · File (allowlist) · Xong khi · Giờ**.

### F — Nền tảng chung
| Mã | Tên | Chủ/Review | Phụ thuộc | File | Xong khi | Giờ |
|---|---|---|---|---|---|---:|
| **AG-F01** | Contract nháp: `ReportEnvelope`, `AgentRun/Step/Event` (+`attempt`, `parked_until`, `decision_log`, `risk_class`, `trust_level`), `PipelineDefinition` (điều kiện đạt/hành động sửa/hạn chờ/hành động mặc định), `TrustPolicy`, `Role/Playbook/Rubric`, `AiIntegration/RoutingRule`; đối chiếu `WorkflowEnum` | Dev2 / Dev1 | — | `docs/seo-agent/contracts-draft/**` | Dev1 duyệt; chốt **một** bộ trạng thái bài | 6 |
| **AG-F02** | Xác minh `develop`: chạy test, đăng nhập bản chạy, chụp màn, đối chiếu mockup | Dev2 / Dev1 | — | báo cáo `docs/**` | Báo cáo thật; chưa chạy ghi `Chưa chạy` | 3 |
| **AG-F03** | ADR: lõi orchestrator trong Laravel; vai trò n8n (thông báo/lập lịch); **giấy phép n8n**; quyết định Gateway | Dev1 / Dev2 | — | `docs/seo-agent/adr/**` | Quyết định bằng văn bản | 8 |
| **AG-F04** | Chọn nguồn dữ liệu SEO VN: GSC + từ khóa/SERP, chi phí, giới hạn, dữ liệu gửi ra ngoài | Dev2 / Dev1 + bạn | — | `docs/seo-agent/data-sources.md` | Quyết định *được/không* từng nguồn; thiếu nguồn → nút ghi `Chưa kết nối` | 6 |

### K — Kiến thức (Dev2)
| Mã | Tên | Phụ thuộc | File | Xong khi | Giờ |
|---|---|---|---|---|---:|
| **AG-K01** | Danh mục học (skill→vai trò→playbook→rubric→guardrail); **xác minh quy tắc L1–L9 với tài liệu Google**; một lần chạy thử claude-seo để lấy mẫu | — | `docs/seo-agent/knowledge/**` | Mỗi quy tắc: *đã xác minh/chưa/bác bỏ* + nguồn | 6 |
| **AG-K02** | Định dạng Playbook + `PlaybookRegistry` (version/checksum/lineage; khai báo điều kiện đạt, hành động sửa, hạng rủi ro, hành động mặc định) | F01 | `resources/playbooks/**`, `app/Services/Ai/PlaybookLoader.php` *(phối hợp)* | Run ghi `playbook@version`; thiếu lineage → từ chối | 10 |
| **AG-K03** | **Rubric dạng dữ liệu** (mở rộng `QualityThresholds`; giữ mã luật) + **phản hồi có cấu trúc** cho vòng sửa; rule mới theo K01 | K01 | `app/Services/Content/Quality/**`, `resources/playbooks/rubrics/**` | Test cũ xanh; kết quả luật dùng được làm phản hồi sửa | 12 |
| **AG-K04** | Playbook Audit: ánh xạ mã lỗi crawler ↔ rubric ↔ P0/P1/P2 (**một bảng**), prompt tóm tắt VN, `score_method` | K02, K03 | `resources/playbooks/audit/**`, `IssueCatalog.php` | Cùng URL cho cùng kết luận ở UI và crawler | 10 |
| **AG-K05** | Playbook Chiến lược/Brief: Website Relevance Rule, Site Structure Coverage Rule, chấm khoảng trống | K02, K11 | `resources/playbooks/brief/**` | Brief chỉ gợi ý điều website làm được | 12 |
| **AG-K06** | Playbook Cụm từ khóa (intent, trùng-SERP, **bản giảm cấp** khi chưa có SERP) | K02, F04 | `resources/playbooks/keywords/**` | Không nói "dựa trên SERP" khi không có SERP | 12 |
| **AG-K07** | Playbook Biên tập/QA: Who/How/Why, E-E-A-T, **rào chắn nội dung AI hàng loạt** (giới hạn bài/ngày/site), kiểm chứng claim — *gộp 6.1* | K03, K11, K12 | `resources/playbooks/editor/**`, `Services/Content/Quality/**` | Bài vượt rào chắn bị giữ nháp tự động | 12 |
| **AG-K08** | Playbook Lộ trình (mẫu theo loại hình SME VN; mỗi khuyến nghị có cơ sở/phụ thuộc/cách kiểm chứng) | K05 | `resources/playbooks/plan/**` | Qua bộ mẫu | 10 |
| **AG-K09** | **Bộ đánh giá VN + harness**: mẫu cho từng playbook; so sánh **model × playbook**; **đo vòng tự sửa** (điểm qua từng vòng) | K02 | `tests/Fixtures/golden/**` | Mỗi playbook có `pass_rate`; chưa qua thì **không bật tự động** | 14 |
| **AG-K10** | Playbook Đối thủ/SERP (page-type mismatch, loại tên miền, 3 loại khoảng trống) | K02, F04 | `resources/playbooks/competitor/**` | Chỉ kết luận khi có bằng chứng | 10 |
| **AG-K11** | **Hồ sơ doanh nghiệp & giọng thương hiệu** (form theo website; tiêm vào prompt và rubric) | F01 | `app/Filament/**`, `Support/Agent/Profile/**` | Hai hồ sơ khác nhau cho bài khác nhau | 12 |
| **AG-K12** | **Hạng rủi ro chủ đề + claim nhạy cảm + danh sách nguồn được duyệt** (thấp/vừa/cao; YMYL, giá, pháp lý…) | F01 | `resources/playbooks/risk/**` | Chủ đề được phân hạng; chỉ hạng thấp đủ điều kiện tự đăng | 10 |

### A — Tự động hóa
| Mã | Tên | Chủ/Review | Phụ thuộc | File | Xong khi | Giờ |
|---|---|---|---|---|---|---:|
| **AG-A01** | **Vòng Viết→Chấm→Sửa** (`RefinementLoop` bọc `ContentPipelineService`): chấm bằng code + **model chấm khác**, tối đa N vòng, mỗi vòng phải tăng điểm, hết vòng → giữ nháp | Dev2 / Dev1 | K03, K07, B06 | `app/Services/Content/Refinement/**` | Trên bộ mẫu: tỉ lệ đạt tăng so với một lượt; không quẩn vô hạn (test) | 14 |
| **AG-A02** | **Bảng tự phục hồi** (lỗi → chính sách → hành động; retry/backoff, đổi model, sửa định dạng, ngắt mạch site, **CMS timeout → `needs_reconcile` không retry mù**) | Dev1 / Dev2 | B03, B06 | `app/Services/Agent/Recovery/**` | Mỗi hàng trong bảng §3A.3 có test | 16 |
| **AG-A03** | **Tìm thêm trong nguồn được duyệt**: tìm trên `crawled_urls`, hồ sơ/tài liệu, CSV/GSC (full-text MariaDB trước); nguồn là dữ liệu không đáng tin | Dev2 / Dev1 | K12, K11 | `app/Services/Agent/Retrieval/**` | Trả đoạn + vị trí nguồn; nguồn ngoài danh sách bị từ chối; chỉ dẫn lạ bị bỏ | 14 |
| **AG-A04** | **Giám sát viên LLM + nhật ký quyết định** (chỉ ở điểm quyết định: thử lại/đổi cách/bỏ claim/báo; ngân sách riêng) | Dev1 / Dev2 | B10, B06 | `app/Services/Agent/Supervisor/**` | Mỗi quyết định có lý do + dữ kiện; vượt ngân sách giám sát thì dừng | 14 |
| **AG-A05a** | **Chính sách tin cậy T1** (+`TrustPolicy`, hạng rủi ro; T1 = đẩy CMS nháp) | Dev1 / Dev2 | B01, K12 | `app/Services/Agent/Trust/**` | Không bài nào lên **live** ở T1 (test); đẩy được nháp | 6 |
| **AG-A05b** | **T2 + kiểm mẫu sau đăng + tự giảm bậc** (cần văn bản chính sách) | Dev1 / Dev2 | A05a, A06/A07, K09 | như trên | Mẫu lỗi → tự gỡ + giảm bậc + báo | 12 |
| **AG-A06** | **Gỡ/rollback bài WordPress** (khảo sát khả năng connector) | Dev2 / Dev1 | — | `app/Integrations/WordPress/**` | Gỡ được bài đã đăng, ghi nhật ký, idempotent | 8 |
| **AG-A07** | **Gỡ/rollback bài Magento** | Dev1 / Dev2 | — | `app/Integrations/Magento/**` | Như trên | 10 |

### U — Giao diện (Dev2)
| Mã | Tên | Phụ thuộc | File | Xong khi | Giờ |
|---|---|---|---|---|---:|
| **AG-U00** | Điều hướng: nhóm "Tự động hóa · Quản trị", **Bản tin** làm trang chủ Marketing | F02 | `AdminPanelProvider.php` **(chủ duy nhất)** | Menu đúng | 3 |
| **AG-U01** | Nhãn & thành phần (`StatusPresenter`: mức sẵn sàng, chế độ, *AI thật/giả lập*, đã tự sửa/đã đỗ; `ai-block`) | F02 | `Support/StatusPresenter.php`, `components/studio/**` | Test như `StatusPresenterTest` | 6 |
| **AG-U02** | **Cách hoạt động** + `FeatureRegistry` (`Đang hoạt động` bắt buộc có `evidence`) | U01 | `Pages/HowItWorksPage.php`, `Features/*.php` | Test chặn `active` thiếu bằng chứng | 8 |
| **AG-U03** | **Seam layer**: `RunStore`, `ReportStore`, `PlaybookRegistry`(đọc), `PipelineGateway`, `AiSettingsRepository`, `SegmentRewriteGateway`, `TrustPolicyStore` + `Demo/Null` | F01 | `Support/Agent/**` | Mọi màn đọc qua seam; production không số mẫu | 8 |
| **AG-U04** | **Nhập báo cáo** claude-seo (`audit-data.json`) | U01, U03 | `Support/Agent/Reports/**` | File mẫu hiện; file sai/XSS bị từ chối | 10 |
| **AG-U05** | **Audit**: Khối AI + `DbSeoAuditSource` (đọc `seo_audits/crawled_urls`) + `ImportedReportSource` + nút *Quét lại* (khi có `B00`) | U03, K04 | `Pages/AuditPage.php`, `Support/SeoAudit/*Source.php` | Hiển thị quét thật + báo cáo nhập | 12 |
| **AG-U06a/b/c** | Khối AI + nút can thiệp ở Từ khóa / Đối thủ / Kế hoạch & GSC | U01, U03 | từng `Pages/*Page.php` | Nút disabled + lý do tới khi nối | 5+6+6 |
| **AG-U07** | **Vai trò & Kỹ năng** (rubric, guardrail, xuất xứ, điều kiện đạt, hạng rủi ro; **Chạy thử** không ghi DB) | U03, K02 | `Pages/RolesPage.php` | Chạy thử ghi nhãn *thử nghiệm* | 14 |
| **AG-U08** | **Hoạt động AI**: danh sách + chi tiết run; **"đã tự xử lý"** (retry, model dự phòng, vòng sửa với điểm qua từng vòng, claim đã bỏ); **"đã đỗ, tiếp tục việc khác"** (không chặn) | U03 | `Pages/AgentRunsPage.php`, `views/filament/agent/**` | Không "running" vĩnh viễn; mọi quyết định tự động xem được lý do | 10 |
| **AG-U09** | **Tích hợp AI**: provider, khóa ghi-không-đọc, định tuyến theo **bậc** và **vai trò viết/chấm**, ngân sách | U03, F01 | `Pages/AiIntegrationsPage.php` | Khóa không lộ (test); thiếu khóa → "không chạy", không giả lập | 10 |
| **AG-U10a/b/c** | **Sửa theo đoạn** (người ghi đè): chọn vùng + ô yêu cầu / diff & lịch sử / claim thiếu nguồn & xung đột phiên bản | U01, U03 | `article-version/**`, `Support/Diff/**` | Không ghi đè im lặng; ghi lại ai/AI sửa | 10+10+8 |
| **AG-U11** | **Chiến dịch & mức tự động**: công tắc mức tự động từng giai đoạn; **thang tin cậy**; ngân sách; **hai loại dừng** (Dừng xuất bản = `EmergencyStopService`; Tạm dừng pipeline = qua Gateway) | U03, U20 | `ArticleResource*`, `Pages/Campaign*`; chạm `ListPublications.php` (rebase) | Banner dừng khẩn cấp toàn cục; **không có trạng thái nào bắt người phải bấm** | 12 |
| **AG-U12** | **Ngoại lệ (tùy chọn) & ghi đè hàng loạt** (huỷ/gỡ/giữ nhiều bài một lượt, vẫn có nhật ký từng bài) | U03 | `Widgets/DecisionsWidget.php` | Chỉ hiện việc đỗ/ngoại lệ; không chặn pipeline | 12 |
| **AG-U13** | Widget Tổng quan (run, chi phí, đỗ) | U03 | `Widgets/AgentRuns*.php` | "Chưa có dữ liệu" khi trống | 6 |
| **AG-U14** | **Bộ kiểm thử kịch bản** S01–S46 (cập nhật cho tự động) + ma trận quyền + helper phát lại sự kiện (**chỉ test/dev**) | U05, U08 | `tests/**` | Mỗi S-xx `pass/fail/not_run` | 10 |
| **AG-U15** | Nối seam sang DB (`Eloquent*Store`) | B01, B02, B03, U03 | `Support/Agent/Eloquent/**` | Giao diện không đổi; dữ liệu bền | 8 |
| **AG-U16** | **Chạy thử end-to-end thật** + cập nhật `FeatureRegistry` + báo cáo 4 mức | Lát 2 | `docs/**`, `Features/*.php` | Báo cáo có `run_id`, log, URL | 6 |
| **AG-U17** | **Pipeline map** (vào menu khi ≥3 bước đã nối) | U03, U01 | `Pages/PipelinePage.php`, `resources/pipelines/**` | Nút chưa có sự kiện thật = nét đứt | 12 |
| **AG-U18** | **Chỉ số mức tự động** (§3A.6) | U03 | `Pages/MetricsPage.php` | Chỉ số có nguồn; thiếu = "Chưa có dữ liệu" | 8 |
| **AG-U19** | **Bản tin hằng ngày** (đã làm gì, đã tự sửa gì, việc đỗ, kiểm mẫu, chi phí) | U03, B11 | `Pages/DigestPage.php` | Đọc từ `RunStore`; không số giả | 10 |
| **AG-U20** | **Chính sách tin cậy** (bậc, ngưỡng, hạng rủi ro được tự đăng, nhật ký ai nâng bậc) | U03, A05a | `Pages/TrustPolicyPage.php` | Nâng bậc là hành động của người, ghi nhật ký | 8 |

### B — Backend (Dev1)
| Mã | Tên | Phụ thuộc | File | Xong khi | Giờ |
|---|---|---|---|---|---:|
| **AG-B00** | Job nền quét audit (bọc `SeoAuditService`) | — | `app/Jobs/**` | Không chặn request; khóa theo site | 4 |
| **AG-B08** | Bền hóa trạng thái dừng khẩn cấp (**issue #9**) | — | `EmergencyStopService.php` | Sau `cache:clear` cờ dừng còn | 4 |
| **AG-B01** | Migration `agent_runs`, `agent_run_steps`, `agent_events`, `agent_reports` (+`attempt`, `parked_until`, `decision_log`, `playbook_ref`, `risk_class`) | F01, F03 | `database/migrations/**`, `app/Models/**` | Migrate/rollback sạch; không lưu secret | 10 |
| **AG-B02** | Migration `ai_integrations` (khóa mã hóa), `ai_routing_rules`, `trust_policies` | F01 | như trên | Khóa mã hóa; không đọc ngược | 6 |
| **AG-B03** | `RunRecorder` + redaction + chính sách lưu; nhận `AuditSink` trung lập | B01 | `app/Services/Agent/**` | Ghi idempotent; redaction có test | 8 |
| **AG-B06** | `AiModelRouter` + adapter provider thứ 2/3 + vai trò *viết/chấm* + **bỏ fallback im lặng sang Mock** + `rewriteSegment` | B02, K02 | `app/Services/Ai/**` | Lỗi → `failed`, **không** thành giả lập; test đa provider (fake) | 16 |
| **AG-B09** | **Ngân sách + ngắt mạch**: trần theo run/ngày/chiến dịch/site; ngắt khi lỗi/chi phí bất thường; tạm dừng/hủy pipeline; mọi bước tới xuất bản kiểm `EmergencyStopService` | B03, B06 | `app/Services/Agent/Policy/**` | Vượt trần dừng; dừng khẩn cấp thì không đẩy lượt đăng | 12 |
| **AG-B10** | **Orchestrator xác định**: state machine theo `PipelineDefinition`, **checkpoint/resume**, retry, **đỗ việc + hạn chờ + hành động mặc định**, chạy các việc độc lập song song | B03, B09 | `app/Services/Agent/**` | Run không người hoàn tất hoặc đỗ có lý do; không đứng yên khi chờ | 24 |
| **AG-B11** | Thông báo ngoại lệ + bản tin (Telegram/Email; n8n tùy chọn theo F03) — *gộp phạm vi 6.3* | B03 | `app/Services/Notify/**` | Gửi được ngoại lệ; không lộ secret | 10 |
| **AG-B04** | API vào có chữ ký (nếu dùng n8n/bên ngoài) | B01, F03 | `routes/`, `app/Http/**` | Sai chữ ký/replay bị từ chối | 10 |
| **AG-B05** | `PipelineGateway` thật (+ n8n trong compose nếu chọn) | B04, F03 | `docker-compose.yml` | Trigger/pause có chữ ký | 12 |

### Tổng giờ (thô)
| | Lát 0 | Lát 1 | Lát 2 | Lát 3 | Tổng |
|---|---:|---:|---:|---:|---:|
| **Dev2** | **21** (F01 6 · F02 3 · F04 6 · K01 6) | **75** (K02 10 · K03 12 · U00 3 · U01 6 · U02 8 · U03 8 · U08 10 · U19 10 · U15 8) | **120** (K04 10 · K05 12 · K07 12 · K09 14 · K11 12 · K12 10 · A01 14 · A03 14 · U05 12 · U09 10) | **183** (K06 12 · K08 10 · K10 10 · A06 8 · U04 10 · U06abc 17 · U07 14 · U10abc 28 · U11 12 · U12 12 · U13 6 · U14 10 · U16 6 · U17 12 · U18 8 · U20 8) | **399** |
| **Dev1** | **16** (F03 8 · B00 4 · B08 4) | **102** (B01 10 · B02 6 · B03 8 · B06 16 · B09 12 · B10 24 · A02 16 · B11 10) | **6** (A05a) | **58** (A04 14 · A05b 12 · A07 10 · B04 10 · B05 12) | **182** |

⚠ Tổng ≈ **581h**. **Chỉ Lát 0 + Lát 1 (Dev2 96h, Dev1 118h) là mức có thể cam kết trước 09/12**, và Dev1 còn 4.1/4.2 (28h). Lát 2 ≈ 126h cần bạn duyệt lại lịch; Lát 3 chỉ làm khi số liệu Lát 2 đạt. Mọi con số cần Dev estimate.

---

## 5. Chống xung đột — chủ file dùng chung
| File/vùng | Chủ duy nhất |
|---|---|
| `AdminPanelProvider.php` | **AG-U00** |
| `StatusPresenter.php`, `components/studio/**` | **AG-U01** |
| `FeatureRegistry` | **AG-U02** tạo; mỗi task thêm file entry riêng |
| `Support/Agent/**` (seam) | **AG-U03**, sau đó thư mục con riêng |
| `resources/playbooks/**` | **AG-K02** tạo khung; mỗi K-task một thư mục con |
| `app/Services/Content/Quality/**` | **K03** → **K07** (tuần tự) |
| `app/Services/Content/Refinement/**` | **AG-A01** |
| `app/Services/Agent/**` | **B03** tạo; mỗi task thư mục con riêng (`Recovery/`, `Retrieval/`, `Supervisor/`, `Trust/`, `Policy/`) |
| `app/Services/Ai/**` | **AG-B06** (+ K02 phối hợp `PlaybookLoader`) |
| `ArticleVersionResource*`, `article-version/**` | **U10a→b→c** (tuần tự) |
| `ListPublications.php` | Dev1 vừa sửa (3.6) → **U11** rebase |
| `app/Integrations/WordPress/**`, `Magento/**` | **A06** (Dev2), **A07** (Dev1) |
| `database/migrations/**`, `app/Models/**`, `app/Jobs/**` | **Dev1** |
Làn song song đề xuất: **Dev2 tối đa 4**, **Dev1 2**.

---

## 6. Cắm pipeline/API sau này — giao diện không phải sửa
| Seam | Hôm nay → | Khi nối |
|---|---|---|
| `RunStore`, `ReportStore` | Demo/Nhập tay/`DbSeoAuditSource` → | `Eloquent*` (B01/B03/U15) |
| `PipelineGateway` | Null (disabled + lý do) → | Gateway thật (B10/B05) |
| `AiSettingsRepository` | Demo/`.env` → | `ai_integrations/routing` (B02) |
| `TrustPolicyStore` | Demo → | `trust_policies` (A05) |
| `SegmentRewriteGateway` | Giả lập có chip → | `rewriteSegment` qua router (B06) |
| `PlaybookRegistry` | Đọc file | giữ nguyên (repo là nguồn sự thật) |
**Checklist "đã cắm xong":** sự kiện thật làm bản đồ chuyển *Đã nối*; một lần chạy có `run_id`, `playbook@version`, model, chi phí, **nhật ký quyết định**; lỗi provider → `failed`, không nội dung giả lập lọt vào bài; T1 không bài nào lên live; không secret trong log/HTML.

---

## 7. Kiểm chứng và nghiệm thu tự động hóa
**4 mức bằng chứng** (`AGENTS.md`): code → test giả lập → staging → người thật nghiệm thu. UI thêm ảnh chụp 375/768/1440 sáng+tối; ghi công cụ nào chạy thật, nào chưa.

**Nghiệm thu "chạy không người" (Lát 2) bằng số liệu** — chạy N lần (ví dụ 20) trên bộ chủ đề mẫu, báo cáo:
| Chỉ số | Cách đo | Ngưỡng gợi ý (cần bạn chốt) |
|---|---|---|
| Tỉ lệ hoàn tất không người | run tới nháp CMS / tổng run | ≥ 80% |
| Tỉ lệ tự sửa thành công | bài đạt rubric sau ≤ N vòng / bài không đạt lần đầu | ≥ 60% |
| Tỉ lệ đỗ/ngoại lệ | run đỗ / tổng | ≤ 20% |
| Chi phí/bài | tổng chi phí run / bài | ≤ trần ngân sách |
| **Số bài lên live ở T1** | đếm | **= 0** |
| Nội dung giả lập lọt vào bài | quét `ai_mode=mock` | **= 0** |
| Lỗi khi kiểm mẫu bởi người | người chấm mẫu | ghi nhận, không có ngưỡng cứng lúc đầu |
Playbook chưa qua `AG-K09` **không** được bật tự động. Chỉ nâng bậc tin cậy khi số liệu đạt và có văn bản của bạn.

---

## 8. Việc gửi Dev1
- **Đã gửi:** issue #9 (`AG-B00`, `AG-B08`, ánh xạ mã lỗi, công suất).
- **Sẽ gửi sau khi bạn duyệt:** duyệt contract `AG-F01` + chốt một bộ trạng thái bài; `AG-F03` (lõi Laravel, vai trò n8n, giấy phép); allowlist `resources/playbooks/`, `Support/Agent/`, `resources/pipelines/`, `app/Services/Agent/**`, hook `AdminPanelProvider`; lịch `B01/B02/B03/B06/B09/B10/A02/B11`; khả năng **gỡ bài** của connector (`A06/A07`); PR-C (`campaigns`, `approved_version_id`, `held`) vẫn chưa thấy.

---

## 9. Khối đề xuất cho `TASKS.md` (CHƯA GHI)
```markdown
## MỐC M09: ĐỘI AI SEO TỰ ĐỘNG — HỌC TỪ claude-seo (đề xuất — ngoài WBS gốc, chưa estimate chính thức)
| Mã việc | Chủ nhiệm | Reviewer | Giờ (thô) | Phụ thuộc | Nhánh đề xuất | Trạng thái | Nội dung chính |
| **AG-F01…F04** | Dev2 (F03: Dev1) | chéo | 6/3/8/6h | - | `docs/m09/ag-fNN/<dev>/<slug>` | Chưa bắt đầu | Contract nháp, xác minh develop, ADR, nguồn dữ liệu SEO VN |
| **AG-K01…K12** | Dev2 | Dev1 | 6–14h/mã | F01 | `feat/m09/ag-kNN/dev2/<slug>` | Chưa bắt đầu | Playbook, Rubric dữ liệu, bộ đánh giá VN, hồ sơ doanh nghiệp, hạng rủi ro |
| **AG-A01…A07** | Dev1/Dev2 | chéo | 6–16h/mã | xem kế hoạch | `feat/m09/ag-aNN/<dev>/<slug>` | Chưa bắt đầu | Vòng tự sửa, tự phục hồi, tìm thêm, giám sát viên, thang tin cậy, gỡ bài |
| **AG-U00…U20** | Dev2 | Dev1 | 3–14h/mã | xem kế hoạch | `feat/m09/ag-uNN/dev2/<slug>` | Chưa bắt đầu | Giao diện hai lớp, bản tin, hoạt động AI, chính sách tin cậy… |
| **AG-B00…B11** | Dev1 | Dev2 | 4–24h/mã | xem kế hoạch | `feat/m09/ag-bNN/dev1/<slug>` | Chưa bắt đầu | Migration, router đa model, ngân sách, orchestrator, thông báo |
```

---

## 10. Rủi ro và điều chưa biết
| Rủi ro | Giảm thiểu |
|---|---|
| Khối lượng ≈ 581h so với nhân lực còn lại | **Chỉ cam kết Lát 0–1**; Lát 2–3 chờ duyệt lại; mỗi lát độc lập |
| Tự đăng nội dung AI hàng loạt có thể hại SEO (chính claude-seo xếp là rủi ro phạt) | T1 mặc định; hạng rủi ro; giới hạn bài/ngày; kiểm mẫu; tự gỡ; văn bản chính sách |
| Fallback Mock + autopublish → đăng nội dung giả lập thật | `B06` là điều kiện; nghiệm thu "mock lọt = 0" |
| Vòng tự sửa không cải thiện hoặc quẩn | Giới hạn N, yêu cầu tăng điểm mỗi vòng, đo bằng `K09` |
| "Tìm thêm" mang nguồn kém/chỉ dẫn độc | Chỉ nguồn được duyệt; nguồn là dữ liệu không đáng tin; bỏ claim thay vì bịa |
| Chi phí vòng lặp tăng vọt | `B09` ngân sách + ngắt mạch ở Lát 1 |
| Connector chưa chắc gỡ được bài | Khảo sát đầu `A06/A07`; T2 chỉ bật khi có gỡ bài |
| Quy tắc SEO của claude-seo chưa xác minh | `K01` bắt buộc; không đổi rubric trước xác minh |
| Chất lượng tiếng Việt chưa biết | `K09` + người thật chấm |
| Công suất Dev1 (4.1/4.2) | Lát nhỏ trước; phần còn lại sau pilot |
| Tôi chưa chạy claude-seo/vòng tự sửa với model thật | `K01` (chạy thử), `K09` (đo vòng sửa) |

## 10A. Bổ sung sau rà soát khả dụng (v0.3.1, 07/11/2026)

Rà soát khả dụng mẫu giao diện (`USABILITY_TEST_SCRIPT.md`) cho thấy giao diện hứa nhiều hơn kế hoạch đã có task. Các task dưới đây **bổ sung**, chưa thay đổi mục nào ở trên. Giờ vẫn là ước lượng thô.

| Mã | Tên | Chủ/Review | Phụ thuộc | File | Xong khi | Giờ | Lát |
|---|---|---|---|---|---|---:|---|
| **AG-F05** | **Chạy thử nghiệm khả dụng với 5 người** trên mẫu theo `USABILITY_TEST_SCRIPT.md`, báo cáo vấn đề và sửa mẫu | Dev2 / Dev1 + bạn | — | `docs/**` | Báo cáo kết quả thật; nhiệm vụ nào < 80% thành công được sửa mẫu rồi thử lại 3 người | 6 | 0 |
| **AG-U21** | **Trang gộp bằng tab** (Nghiên cứu = Từ khóa·Kế hoạch·Đối thủ; Lịch đăng = Lịch tuần·Lịch xuất bản; Phân tích = Audit·Search Console) + **chuyển hướng đường dẫn cũ**; menu 6 mục + "Nâng cao" theo vai trò | Dev2 / Dev1 | U00, F05 | `app/Filament/Pages/**`, `AdminPanelProvider.php` (qua U00) | Mọi trang cũ truy cập được từ tab; đường dẫn cũ/dấu trang vẫn đúng; test điều hướng theo vai trò | 18 | 1 |
| **AG-U22** | **Ba chế độ cài sẵn** (Thận trọng/Cân bằng/Tự chủ): **bảng ánh xạ chế độ → cấu hình** (mức tự động từng giai đoạn, bậc tin cậy, giới hạn bài/ngày); "Tùy chỉnh" hiện công tắc chi tiết | Dev2 / Dev1 | U03, A05a | `Support/Agent/Modes/**`, `Pages/Campaign*` | Chọn chế độ → cấu hình đúng theo bảng (test); 'Tự chủ' khóa kèm lý do tới khi đủ điều kiện | 8 | 1 |
| **AG-U26** | **Ma trận quyền theo vai trò** (admin / trưởng nhóm duyệt / biên tập) cho mọi hành động mới (đổi chế độ, dừng, thêm nguồn, nâng mức…) dùng `Policies` | Dev2 / Dev1 | U03 | `app/Filament/**`, `app/Policies/**` *(Dev1)* | Test ma trận có ca âm tính | 6 | 1 |
| **AG-B12** | **Ghi vết quyết định có cấu trúc** (luật đã kiểm, kết quả, luật quyết định, thời điểm) để giải thích "Vì sao?"; gộp vào `RunRecorder`/orchestrator | Dev1 / Dev2 | B03 | `app/Services/Agent/**` | Mỗi quyết định tự động truy vấn được dưới dạng danh sách luật + kết quả | 10 | 1 |
| **AG-U23** | **Hộp "Vì sao?"** + **Bản tin 3 trạng thái** (bình thường / mới cài / ngày nhiều sự cố, gom nhóm tối đa 4) | Dev2 / Dev1 | U03, B12 | `Pages/DigestPage.php`, `views/filament/agent/**` | Lý do hiển thị từ vết quyết định thật; không số giả | 10 | 2 |
| **AG-U24** | **Hoàn tất cài đặt 5 bước** (nối website → hồ sơ → nguồn AI được dùng → chọn chế độ → **chạy thử 1 bài có người xem**) | Dev2 / Dev1 | K11, K12, A03, U22 | `Pages/Onboarding*.php` | Người mới hoàn tất mà không cần hướng dẫn riêng | 12 | 2 |
| **AG-K13** | **Từ/ý bị cấm kiểm bằng luật** (rule mới trong rubric: không chỉ nằm trong prompt); mặc định theo website, chiến dịch ghi đè | Dev2 / Dev1 | K03, K11 | `Services/Content/Quality/**`, `resources/playbooks/**` | Bài chứa từ cấm bị giữ nháp tự động (test) | 6 | 2 |
| **AG-U25** | **Hướng dẫn & Brief** (hướng dẫn chiến dịch, xem/sửa brief trước khi AI viết, **khóa brief** theo giờ); thêm lối vào từ Bài viết và Bản tin | Dev2 / Dev1 | K05, K13, B13 | `Pages/CampaignGuidePage.php` | Chỉnh brief trước giờ khóa có hiệu lực; sau giờ khóa hiển thị đúng trạng thái | 14 | 2 |
| **AG-B13** | **Lịch & khóa brief** (scheduler bắt đầu viết đúng giờ; phiên bản brief; không viết khi đang chỉnh) | Dev1 / Dev2 | B01, B10 | `app/Services/Agent/**`, `app/Console/**` | Brief bị sửa sau giờ khóa không làm đổi bản đang viết | 8 | 2 |

**Điều chỉnh liên quan:**
- `AG-U00` (3h) chỉ là móc điều hướng; việc gộp trang thực nằm ở `AG-U21`.
- `AG-A05b` (tự đăng mức 2) cần thêm **chỉ số kết quả** từ Search Console (tỉ lệ bài được lập chỉ mục sau 4 tuần) cạnh các chỉ số tự động — phụ thuộc task `5.1`.
- Nút "Tạm dừng AI" **chỉ được bật** khi `AG-B09` xong; trước đó giữ khóa và ghi "chưa nối". Hai nút dừng luôn tách riêng.
- Nút "Khôi phục số liệu bị bỏ" phải kèm cảnh báo (bài giữ nháp tới khi có nguồn).

**Giờ cộng thêm:** Dev2 **80h** (F05 6 · U21 18 · U22 8 · U26 6 · U23 10 · U24 12 · K13 6 · U25 14); Dev1 **18h** (B12 10 · B13 8). Tổng toàn bộ `M09` ≈ **679h** (Dev2 479h, Dev1 200h). **Vẫn chỉ cam kết Lát 0–1**; Lát 0 nay gồm `F05`, Lát 1 gồm `U21, U22, U26, B12`. Cộng lại, **Lát 0–1 ≈ Dev2 134h, Dev1 128h** (trước đó 96h/118h) — đây là mức cần Dev estimate lại trước khi cam kết.

---

## 11. Việc cần bạn duyệt
1. Hướng **tự động là mặc định**, thang tin cậy, **mặc định T1**, đường mỏng trên **WordPress**.
2. Milestone `M09`, mã `AG-F/K/A/U/B`, **bốn lát** — chỉ cam kết **Lát 0–1** trước.
3. Chốt **văn bản chính sách tự đăng** (hoặc xác nhận chỉ T1 trong M09).
4. Danh sách **nguồn được duyệt** và **hạng rủi ro chủ đề** (`AG-K12`) — bạn là người biết nghiệp vụ.
5. Gửi tiếp các yêu cầu ở §8 cho Dev1.
