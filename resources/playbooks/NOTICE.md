# Ghi công (NOTICE)

Các playbook trong thư mục này **học cách làm và quy tắc** từ dự án mã nguồn mở
[`claude-seo`](https://github.com/AgriciDaniel/claude-seo) (giấy phép MIT, © tác giả gốc), được chuyển ngữ và chỉnh cho hệ thống này.
Mỗi playbook ghi xuất xứ cụ thể ở khóa `lineage` (nguồn, phiên bản, giấy phép, chỗ đã chỉnh).

Nếu sau này dùng nội dung từ phần `seo-flow` (CC BY 4.0, © Daniel Agrici) hoặc ghi công riêng của tác giả một skill khác,
phải bổ sung dòng ghi công tương ứng ở đây và trong `lineage` của playbook đó.

## Danh sách skill claude-seo đã học (AG-S10)

Nguồn: [`claude-seo`](https://github.com/AgriciDaniel/claude-seo), giấy phép **MIT**, © Daniel Agrici (AgriciDaniel) và các tác giả đóng góp.
Các playbook chỉ **chuyển thể ý tưởng và quy trình** sang tiếng Việt, không sao chép nguyên văn và không nhúng mã chạy của dự án gốc.

| Skill gốc | Phiên bản | Tác giả đóng góp ghi trong skill gốc | Dùng cho playbook |
|---|---|---|---|
| `seo` | 2.5.0 | AgriciDaniel | `manager/seo-orchestrator`, `manager/weekly-review` |
| `seo-audit` | 2.5.0 | AgriciDaniel | `auditor/audit-onpage`, `auditor/audit-summary`, `auditor/audit-site-rootcause` |
| `seo-page` | 2.5.0 | AgriciDaniel | `auditor/audit-onpage` |
| `seo-technical` | 2.5.0 | AgriciDaniel | `auditor/audit-technical` |
| `seo-sitemap` | 2.5.0 | AgriciDaniel | `auditor/audit-technical` |
| `seo-schema` | 2.5.0 | AgriciDaniel | `auditor/audit-technical`, `auditor/audit-schema`, `strategist/schema-plan` |
| `seo-cluster` | 2.5.0 | Lutfiya Miller (tác giả gốc), AgriciDaniel | `researcher/keyword-cluster`, `researcher/cannibalization-check`, `strategist/internal-linking` |
| `seo-sxo` | 2.5.0 | Florian Schmitz (tác giả gốc), AgriciDaniel | `analyst/serp-gap` |
| `seo-competitor-pages` | 2.5.0 | AgriciDaniel | `analyst/serp-gap` (nguyên tắc công bằng khi so sánh) |
| `seo-content-brief` | 1.0.0 | puneetindersingh | `analyst/serp-gap`, `strategist/content-plan`, `writer/article-draft`, `brief/content-brief` |
| `seo-plan` | 2.5.0 | AgriciDaniel | `strategist/content-plan` |
| `seo-content` | 2.5.0 | AgriciDaniel | `writer/article-draft`, `editor/eeat-check`, `editor/qa-review` |
| `seo-local` | 2.5.0 | AgriciDaniel | `researcher/local-keywords` |
| `seo-image-gen` | 2.5.0 | AgriciDaniel | `designer/image-brief` |
| `seo-images` | 2.5.0 | AgriciDaniel | `designer/image-qa` |
| `seo-backlinks` | 2.5.0 | AgriciDaniel | `analyst/backlink-gap` |
| `seo-drift` | 2.5.0 | Dan Colta (tác giả gốc), AgriciDaniel | `monitor/drift-baseline` |
| `seo-google` | 2.5.0 | AgriciDaniel | `monitor/drift-baseline` (chỉ ý về dữ liệu Search Console), `monitor/refresh-trigger` |

Mỗi playbook nói rõ chỗ đã **bỏ** và **đổi** ở khóa `lineage.adaptations`. Bản đồ chi tiết (đã học gì, bỏ vì sao, cần xác minh gì) ở
`docs/seo-agent/knowledge/CLAUDE_SEO_LEARNING_MAP.md`. Mọi playbook hiện là `draft` vì chưa qua bộ mẫu tiếng Việt (AG-K09).

## Bổ sung giai đoạn 2 (12/11/2026)

Chín playbook mới (`strategist/internal-linking`, `strategist/schema-plan`, `auditor/audit-site-rootcause`, `auditor/audit-schema`, `researcher/cannibalization-check`, `researcher/local-keywords`, `analyst/backlink-gap`, `monitor/refresh-trigger`, `manager/weekly-review`) cũng chỉ chuyển thể ý tưởng và quy trình từ các skill ghi ở bảng trên, đều ở trạng thái `draft`.
Cách ghi công khi tuỳ biến: mỗi playbook giữ `lineage.source` là skill gốc, `lineage.source_version` đúng phiên bản trong `claude-seo/skills/<skill>/SKILL.md`, `license: MIT`, và `adaptations` nêu cụ thể đã bỏ/đổi gì. Giấy phép MIT yêu cầu giữ thông báo bản quyền khi sao chép phần đáng kể; vì ta chỉ chuyển thể ý tưởng bằng lời mới nên dòng ghi công ở tệp này và ở `lineage` là đủ, và không nhúng mã gốc. Skill `seo-flow` (CC BY 4.0) chưa được dùng; nếu dùng phải ghi công riêng theo CC BY 4.0.
