# Nguồn dữ liệu SEO cho thị trường Việt Nam (AG-F04) — đề xuất quyết định

> v1.0 · 07/11/2026 · **Đề xuất để chủ dự án quyết**, chưa phải quyết định. Xác minh bằng tìm kiếm trong tài liệu chính thức; **chưa đọc từng trang, chưa thử API thật, chưa có báo giá**. Trạng thái: ✅ đã thấy trong tài liệu · 🟡 một phần · ⬜ chưa xác minh.
> Lý do tồn tại: không có nguồn dữ liệu thật thì các nút "Khám phá từ khóa/đối thủ" chỉ là AI đoán (trái `AGENTS.md` §1.2). Cho tới khi chọn xong, các chức năng này giữ nhãn **"Chưa kết nối"**.

## 1. Cần dữ liệu gì, để làm gì
| Nhu cầu | Dùng cho | Hiện có trong repo |
|---|---|---|
| **Hiệu suất thật của website** (hiển thị, nhấp, CTR, vị trí theo truy vấn/trang) | Đo hiệu quả; điều kiện lên mức tự đăng; đề xuất cập nhật bài | UI Search Console (dữ liệu minh họa); task 5.1 chưa làm |
| **Khối lượng tìm kiếm / ý tưởng từ khóa** | Chọn chủ đề, ưu tiên | Nhập CSV (2.1); gom cụm bằng `MockClusterer` |
| **Kết quả SERP của đối thủ** | Khoảng trống nội dung, trùng SERP, page-type | `CompetitorsPage` (dữ liệu minh họa) |

## 2. Các lựa chọn đã xem
### A. Google Search Console API (miễn phí, dữ liệu của chính website)
- ✅ Truy vấn hiệu suất theo trang/truy vấn; mặc định 1.000 dòng, tối đa 25.000/lần, phân trang bằng `startRow`; **không đảm bảo trả đủ mọi dòng** (chỉ phần đầu).
- ✅ Giới hạn dữ liệu xuất khoảng **50.000 dòng/ngày/site/loại tìm kiếm**; hạn mức theo site/người dùng/dự án (ví dụ 1.200 truy vấn/phút/site) — nguồn nêu; tải càng nặng khi nhóm theo trang + truy vấn.
- ✅ Dữ liệu thường có sau 2–3 ngày.
- ⬜ Thời gian lưu dữ liệu (thường được nhắc là 16 tháng) — **chưa tìm thấy nguồn trong lần tra này**.
- **Phù hợp:** nền tảng bắt buộc để **đo hiệu quả**; đã nằm trong task 5.1.
- **Rủi ro/việc cần làm:** cần OAuth read-only và quyền sở hữu website trong Search Console; chỉ có dữ liệu của site mình (không có đối thủ); không có khối lượng tìm kiếm của từ khóa chưa từng hiển thị.

### B. Dịch vụ dữ liệu từ khóa trả phí — ví dụ DataForSEO (Google Ads Keywords Data)
- ✅ Lấy khối lượng tìm kiếm và chỉ số khác cho tối đa 1.000 từ khóa/lần, ý tưởng từ khóa theo từ gốc hoặc tên miền; giới hạn 12 yêu cầu/phút/tài khoản (điểm cuối Live); có môi trường **sandbox miễn phí** để thử; có thể không có dữ liệu cho một số từ khóa.
- 🟡 Mã địa điểm/ngôn ngữ lấy từ điểm cuối `locations`/`languages`. **Chưa xác nhận Việt Nam và tiếng Việt có được hỗ trợ** từ kết quả tra này — phải kiểm bằng các điểm cuối đó.
- ⬜ **Chưa có giá**; tính phí theo từng yêu cầu (trang giá riêng).
- **Dữ liệu gửi ra ngoài:** danh sách từ khóa/tên miền gửi sang bên thứ ba → cần chính sách tối thiểu hóa dữ liệu (ví dụ chỉ gửi từ khóa, không gửi nội dung bài).
- **Phù hợp:** nếu cần khối lượng tìm kiếm và ý tưởng từ khóa mà không muốn tự quản lý tài khoản quảng cáo.

### C. Google Ads API — Keyword Planner (`GenerateKeywordIdeas`)
- ✅ Hạn mức 1 yêu cầu/giây/tài khoản; hạt giống 1–20 từ khóa hoặc URL/tên miền.
- 🟡 Cách cấp quyền thay đổi theo tài liệu (mức truy cập gắn với dự án Google Cloud; trang tài liệu **mâu thuẫn nhau** về điều kiện "sử dụng cho phép") → **cần đọc chính sách hiện hành** trước khi quyết.
- ⬜ Chưa biết có yêu cầu chi tiêu quảng cáo hay không.
- **Phù hợp:** miễn phí về dữ liệu nhưng tốn công cấu hình/xin quyền, rủi ro vi phạm chính sách.

### D. Nhập tay/CSV (đã có)
- ✅ Đã có ở 2.1. Người dùng tự xuất từ công cụ họ có (Search Console, công cụ từ khóa) rồi tải lên.
- **Phù hợp:** **bước đầu**, không tốn phí, không gửi dữ liệu ra ngoài; chất lượng phụ thuộc người nhập.

### E. Dữ liệu SERP đối thủ
- ⬜ **Chưa tra** (nhà cung cấp SERP, chi phí, tuân thủ điều khoản). Cho tới khi có, **Phân tích đối thủ** phải chạy ở chế độ giảm cấp (từ danh sách đối thủ người dùng nhập + trang công khai đã được phép quét) và ghi rõ giới hạn, **không** kết luận "dựa trên SERP".

## 3. Đề xuất (để bạn quyết)
| Bước | Nguồn | Ghi chú |
|---|---|---|
| Làm ngay (không chi phí) | **A. Search Console** (task 5.1) + **D. CSV** | Đủ để đo hiệu quả và chọn chủ đề ban đầu |
| Thử nghiệm ngắn, chưa trả tiền | **B. dịch vụ từ khóa** trong sandbox: kiểm hỗ trợ **Việt Nam/tiếng Việt**, chất lượng dữ liệu, giá thực tế | Việc `AG-F04b` (≈ 4h) |
| Hoãn | **C. Keyword Planner**, **E. SERP** | Chỉ xem xét nếu B không đạt hoặc quá đắt |
| Trong lúc chờ | Nút "Khám phá từ khóa/đối thủ" giữ nhãn **Chưa kết nối**, playbook dùng **bản giảm cấp** | Không dùng số liệu giả |

## 4. Câu hỏi cần bạn trả lời
1. Ngân sách tối đa mỗi tháng cho dữ liệu từ khóa/SERP là bao nhiêu (kể cả 0)?
2. Dữ liệu nào **được phép** gửi ra bên thứ ba (từ khóa? tên miền? không gì cả)?
3. Có sở hữu/quyền quản trị Search Console cho các website cần đo không?
4. Chấp nhận chạy "bản giảm cấp" (CSV + Search Console) ở giai đoạn đầu không?

## Nguồn
- [Getting your performance data — Search Console API](https://developers.google.com/webmaster-tools/v1/how-tos/all-your-data)
- [Usage Limits — Search Console API](https://developers.google.com/webmaster-tools/limits)
- [A deep dive into Search Console performance data filtering and limits](https://developers.google.com/search/blog/2022/10/performance-data-deep-dive)
- [Google Ads Keywords Data API: Overview — DataForSEO](https://docs.dataforseo.com/v3/keywords_data-google_ads-overview/)
- [Setting Live 'Google Ads Search Volume' Tasks — DataForSEO](https://docs.dataforseo.com/v3/keywords_data-google_ads-search_volume-live/)
- [Generate keyword ideas — Google Ads API](https://developers.google.com/google-ads/api/docs/keyword-planning/generate-keyword-ideas)
- [Access levels and permissible use — Google Ads API](https://developers.google.com/google-ads/api/docs/api-policy/access-levels)
- [API limits and quotas — Google Ads API](https://developers.google.com/google-ads/api/docs/best-practices/quotas)
