# Bàn giao để test tiếp (21/11/2026)

Nhánh: `feat/M09/ag-s28/dev2/quan-ly-seo-dieu-phoi` (worktree `.claude/worktrees/quan-ly-seo-dieu-phoi`). **Chỉ commit cục bộ, CHƯA push**; PR #55 mới có đến `84cd784`. Không push/mở PR/xóa nhánh khi chưa được người dùng duyệt.

## 1. Đã có trên nhánh (commit cục bộ)
| Commit | Nội dung |
|---|---|
| `84cd784` | Nạp huấn luyện từ file, file mẫu tải về, hồ sơ vai trò áp dụng vào các bước AI |
| `faf7f5e` | File mẫu kế hoạch nhiều sheet, brief 16 trường, hộp chi tiết nổi ở Kho trí tuệ, gọn giao diện lập kế hoạch |
| `f2baf80` | CPC/mức cạnh tranh, khớp liên kết nội bộ, bỏ URL nội bộ (chống SSRF) |
| `8b8c2c9` | Gộp nhánh `claude/chuyen-gia-vai-tro-2cdd52`: 17 playbook chuyên gia y khoa/dinh dưỡng, mẫu hồ sơ, tiêu chí cho 14 vai trò |
| `6cb1f01` | Liên kết nội bộ khớp bài đã đăng thì ghi kèm URL thật |
| `fc4b300` | Migration `articles.brief` (JSON) + 4 cột xuất bản: chuyên mục, slug, tác giả, người duyệt |

Báo cáo chi tiết: `docs/seo-agent/DANH_GIA_DOI_AI_VA_DE_XUAT_2026-11-21.md` (có kịch bản thử 10 phút).

## 2. Mức kiểm chứng (trung thực)
- Đã viết code + test giả lập: **đạt**. Toàn bộ `tests/Feature tests/Unit`: 2400 test, 20 lỗi, **trùng đúng 20 lỗi có sẵn trên origin/main** (thiếu khóa API; danh sách trong báo cáo/`scratchpad/fail_before.txt` của phiên cũ). Không có lỗi mới.
- Thử giao diện cục bộ (instance dev, STUDIO_DEMO): đã thử giao lại phạm vi, huấn luyện/nạp từ file, nhập kế hoạch (mẫu + CSV tự do), hộp chi tiết Kho trí tuệ. **Migration `brief` và 4 cột xuất bản mới chỉ chạy qua test, chưa thử trên giao diện.**
- **Chưa làm:** AI thật (model thật) cho file tự do/làm lại/ảnh; staging; người thật nghiệm thu.

## 3. Việc cần test tiếp
1. Chạy migration trên bản dev, nhập file mẫu (33 cột) rồi kiểm `articles.brief` (chuyên mục, slug đã chuẩn hóa, người duyệt khớp tài khoản / cảnh báo khi không khớp).
2. Nhập file kế hoạch **không theo mẫu** với AI thật; kiểm cột nhận đúng trường, gợi ý thiếu, cảnh báo.
3. Giao làm lại với phạm vi "chỉ ảnh", "chỉ nguồn", "giữ nguyên bài viết" bằng model thật; kiểm chữ bài không đổi.
4. Ảnh có chữ (overlay_text) với model ảnh thật; ảnh lỗi 429 và lệnh thử lại `agent:retry-images`.
5. Tab "Huấn luyện" mỗi vai trò: "Nạp mẫu chuyên gia" → Lưu → chạy phiên, xem hồ sơ có vào prompt ở 7 vai trò nối (writer, strategist, editor, designer, manager, researcher, file_analyst).
6. Nạp kho 0 nguồn: xem lý do chẩn đoán có đúng không.

## 4. Chưa nối / chưa làm (đừng báo là xong)
- Chuyên mục/slug/tác giả/người duyệt **chỉ lưu**, bộ đăng bài WordPress/Magento và việc giao duyệt chưa dùng (sửa tích hợp, phạm vi Dev1).
- 17 playbook mới **chưa nạp vào bước chạy nào**; chỉ có tác dụng qua hồ sơ huấn luyện của 7 vai trò kể trên. Vai trò `planner`, `fact_checker`, `image_qa` chưa nối bước chạy.
- Sheet "Bài cần cập nhật" chưa làm. "Nguồn nên dùng thành nguồn bắt buộc": người dùng quyết định KHÔNG làm.
- Ảnh bù khi hết hạn ngạch 429; vai trò Người đọc thử.

## 5. Cách chạy
- Test: Docker, image `addp-autoseo-app:latest`, mount worktree vào `/app` và `vendor` chỉ đọc, ví dụ `docker run --rm --entrypoint php --user $(id -u) -v <worktree>:/app -v <repo>/vendor:/app/vendor:ro -w /app -e APP_KEY=<khóa test> -e HOME=/tmp addp-autoseo-app:latest vendor/bin/phpunit tests/Feature/Planning`. Lệnh Bash trong Claude Code cần `dangerouslyDisableSandbox: true` (lỗi seccomp).
- Bản dev tay: container `seo-ag28` cổng 8110 (`STUDIO_DEMO=true`), CSDL SQLite riêng ngoài repo; tài khoản seed trong `DatabaseSeeder` (chỉ dùng cho bản dev cục bộ).
- Máy chủ thật `autoseo.addp.vn`: không có quyền truy cập từ phiên này.

## 6. Quy tắc cần giữ
- Không commit secret/`.env`; không đẩy lên GitHub khi chưa được duyệt.
- Commit: `-c user.name="Dev2" -c user.email="dungpham04012003@gmail.com"` (máy chưa cấu hình git user) + dòng `Co-Authored-By`.
- Pháp lý chỉ hiển thị, không chặn luồng. Báo cáo trung thực theo 4 mức (code → mock → staging → người thật).
- Tài khoản Claude chạy bài tách riêng, không dùng tài khoản code.
- Tệp chưa theo dõi ở checkout chính (`docs/plan/*.md`, `docs/seo-agent/`, README, `bootstrap/cache`) không thuộc nhánh này; đừng `git add -A` ở checkout chính.

## 7. Prompt ngắn giao việc
> Đọc `docs/seo-agent/BAN_GIAO_TEST_TIEP_2026-11-21.md` và mục "kịch bản thử 10 phút" trong `docs/seo-agent/DANH_GIA_DOI_AI_VA_DE_XUAT_2026-11-21.md` (worktree `.claude/worktrees/quan-ly-seo-dieu-phoi`, nhánh `feat/M09/ag-s28/dev2/quan-ly-seo-dieu-phoi`). Chạy lại test (kỳ vọng đúng 20 lỗi cũ do thiếu khóa API), rồi thử trên giao diện các mục ở phần 3, ưu tiên mục 1 (migration `brief`, chưa từng chạy trên giao diện). Ghi mỗi mục: Đạt / Lỗi / Chưa chạy (thiếu khóa hoặc môi trường), kèm bằng chứng; không tuyên bố xong khi chưa chạy thật. Sửa lỗi tìm được và commit cục bộ; KHÔNG push, mở PR hay xóa nhánh khi chưa hỏi tôi.
