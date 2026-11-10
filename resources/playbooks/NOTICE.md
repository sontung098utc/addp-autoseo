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
| `seo` | 2.5.0 | AgriciDaniel | `manager/seo-orchestrator` |
| `seo-audit` | 2.5.0 | AgriciDaniel | `auditor/audit-onpage`, `auditor/audit-summary` |
| `seo-page` | 2.5.0 | AgriciDaniel | `auditor/audit-onpage` |
| `seo-technical` | 2.5.0 | AgriciDaniel | `auditor/audit-technical` |
| `seo-sitemap` | 2.5.0 | AgriciDaniel | `auditor/audit-technical` |
| `seo-schema` | 2.5.0 | AgriciDaniel | `auditor/audit-technical` |
| `seo-cluster` | 2.5.0 | Lutfiya Miller (tác giả gốc), AgriciDaniel | `researcher/keyword-cluster` |
| `seo-sxo` | 2.5.0 | Florian Schmitz (tác giả gốc), AgriciDaniel | `analyst/serp-gap` |
| `seo-competitor-pages` | 2.5.0 | AgriciDaniel | `analyst/serp-gap` (nguyên tắc công bằng khi so sánh) |
| `seo-content-brief` | 1.0.0 | puneetindersingh | `analyst/serp-gap`, `strategist/content-plan`, `writer/article-draft`, `brief/content-brief` |
| `seo-plan` | 2.5.0 | AgriciDaniel | `strategist/content-plan` |
| `seo-content` | 2.5.0 | AgriciDaniel | `writer/article-draft`, `editor/eeat-check`, `editor/qa-review` |
| `seo-drift` | 2.5.0 | Dan Colta (tác giả gốc), AgriciDaniel | `monitor/drift-baseline` |
| `seo-google` | 2.5.0 | AgriciDaniel | `monitor/drift-baseline` (chỉ ý về dữ liệu Search Console) |

Mỗi playbook nói rõ chỗ đã **bỏ** và **đổi** ở khóa `lineage.adaptations`. Bản đồ chi tiết (đã học gì, bỏ vì sao, cần xác minh gì) ở
`docs/seo-agent/knowledge/CLAUDE_SEO_LEARNING_MAP.md`. Mọi playbook hiện là `draft` vì chưa qua bộ mẫu tiếng Việt (AG-K09).
