# Q0: số đo baseline trên bài đã có (chỉ phần tất định)

*13/11/2026. Chạy `agent:eval-baseline --no-ai` trên **bản sao** DB preview `seo-ag-s27` (44 bài đo được). **Giới hạn:** (1) chưa có giám khảo AI nên độ phủ ý định và tỉ lệ trích dẫn được hỗ trợ là "chưa chạy"; (2) gồm nhiều bài thử từ nhiều model/phiên bản luồng khác nhau (kể cả website thử `.invalid`), nên đây là bức tranh của bài ĐÃ CÓ, chưa phải baseline có kiểm soát; (3) "câu sự thật" là xấp xỉ bằng luật, có thể bỏ sót (nhiều bài 0/0). Baseline có kiểm soát cần chạy `--mode=generate` với model thật.*


Giám khảo AI: **chưa có** (các số đo cần AI ghi "chưa chạy", không phải 0)

## Tổng hợp theo hạng rủi ro

| Nhóm | Số bài | Vệ sinh SEO (TB) | Độ phủ ý định | Câu sự thật có trích dẫn | Cặp trích dẫn được hỗ trợ | Bài không có nguồn |
|---|---|---|---|---|---|---|
| all | 44 | 78.1364 | chưa chạy | 16.9% | chưa chạy | 12 |
| high | 16 | 68.625 | chưa chạy | 32.2% | chưa chạy | 6 |
| medium | 13 | 83.9231 | chưa chạy | 12.5% | chưa chạy | 3 |
| low | 15 | 83.2667 | chưa chạy | 0.0% | chưa chạy | 3 |

Không đo được: chưa có bài 4 từ khóa, chưa có phiên bản 18 bài.

## Chi tiết

| Từ khóa | Rủi ro | Từ | Vệ sinh | Độ phủ ý định | Câu sự thật (có trích/tổng) | Trích dẫn được hỗ trợ | Ghi chú |
|---|---|---|---|---|---|---|---|
| HbA1c là gì | high | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| chỉ số HbA1c bao nhiêu là tốt | high | 913 | 55 | chưa chạy | 2/3 | chưa chạy |  |
| chỉ số HbA1c bao nhiêu là tốt | high | 1413 | 50 | chưa chạy | 1/13 | chưa chạy |  |
| chỉ số HbA1c bao nhiêu là tốt | high | 1566 | 45 | chưa chạy | 6/7 | chưa chạy |  |
| chỉ số HbA1c bao nhiêu là tốt | high | 1387 | 80 | chưa chạy | 0/22 | chưa chạy |  |
| chỉ số HbA1c bao nhiêu là tốt | high | 1290 | 55 | chưa chạy | 5/5 | chưa chạy |  |
| chỉ số HbA1c bao nhiêu là tốt | high | 1360 | 50 | chưa chạy | 1/12 | chưa chạy |  |
| chỉ số đường huyết bình thường | high | 928 | 90 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| chỉ số đường huyết bình thường và tiền tiểu đường | high | 1057 | 60 | chưa chạy | 2/12 | chưa chạy |  |
| chỉ số đường huyết bình thường và tiền tiểu đường | high | 1552 | 50 | chưa chạy | 3/21 | chưa chạy |  |
| chỉ số đường huyết bình thường và tiền tiểu đường | high | 1217 | 55 | chưa chạy | 4/18 | chưa chạy |  |
| tiểu đường tuýp 2 là gì | high | 916 | 90 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| biến chứng tiểu đường | high | 912 | 90 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| người tiểu đường nên ăn gì | high | 916 | 90 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| thực phẩm tốt cho người tiểu đường | high | 918 | 90 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| cách trị bệnh tiểu đường tại nhà | high | 274 | 58 | chưa chạy | 0/1 | chưa chạy |  |
| cách trị bệnh tiểu đường tại nhà | high | 918 | 90 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| lợi ích của việc đi bộ mỗi ngày | medium | 1360 | 60 | chưa chạy | 2/2 | chưa chạy |  |
| lợi ích của việc đi bộ mỗi ngày | medium | 1624 | 80 | chưa chạy | 0/6 | chưa chạy |  |
| thuốc hạ đường huyết loại nào tốt | high | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| máy đo đường huyết loại nào chính xác | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| tăng huyết áp là gì | high | - | - | - | - | - | Chưa có bài nào cho từ khóa này. |
| huyết áp bao nhiêu là bình thường | high | - | - | - | - | - | Chưa có bài nào cho từ khóa này. |
| dấu hiệu nhồi máu cơ tim | high | - | - | - | - | - | Chưa có bài nào cho từ khóa này. |
| mỡ máu cao nên ăn gì | high | - | - | - | - | - | Chưa có bài nào cho từ khóa này. |
| cách trị mụn tại nhà | medium | 1725 | 90 | chưa chạy | 0/11 | chưa chạy |  |
| chạy bộ chân đất | medium | 1720 | 93 | chưa chạy | 0/9 | chưa chạy |  |
| tiêu chuẩn GMP-WHO là gì | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| tiêu chuẩn GMP-WHO là gì | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| tiêu chuẩn GMP-WHO là gì | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| tiêu chuẩn GMP-WHO là gì | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| tiêu chuẩn GMP-WHO là gì | medium | 1038 | 70 | chưa chạy | 0/1 | chưa chạy |  |
| tiêu chuẩn GMP-WHO là gì | medium | 1078 | 90 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| tiêu chuẩn GMP-WHO là gì | medium | 1879 | 90 | chưa chạy | 0/8 | chưa chạy |  |
| tiêu chuẩn GMP-WHO là gì | medium | 2256 | 90 | chưa chạy | 0/6 | chưa chạy | không có nguồn |
| tiêu chuẩn GMP-WHO là gì | medium | 1197 | 95 | chưa chạy | 0/0 | chưa chạy |  |
| tiêu chuẩn GMP-WHO là gì | medium | 1689 | 100 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| tiêu chuẩn GMP-WHO là gì | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| so sánh GMP-WHO và EU-GMP | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| so sánh GMP-WHO và EU-GMP | medium | 793 | 85 | chưa chạy | 0/0 | chưa chạy |  |
| hướng dẫn thẩm định quy trình sản xuất thuốc viên nén | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| hướng dẫn thẩm định quy trình sản xuất thuốc viên nén | medium | 763 | 65 | chưa chạy | 0/0 | chưa chạy |  |
| hướng dẫn thẩm định quy trình sản xuất thuốc viên nén | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| máy dập viên là gì và nguyên lý hoạt động | low | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| máy dập viên là gì và nguyên lý hoạt động | low | 941 | 83 | chưa chạy | 0/0 | chưa chạy |  |
| báo giá máy dập viên công nghiệp | low | 1088 | 93 | chưa chạy | 0/7 | chưa chạy |  |
| báo giá máy dập viên công nghiệp | low | 883 | 83 | chưa chạy | 0/1 | chưa chạy | không có nguồn |
| dịch vụ tư vấn xây dựng nhà máy dược đạt GMP | medium | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| dịch vụ tư vấn xây dựng nhà máy dược đạt GMP | medium | 854 | 83 | chưa chạy | 0/1 | chưa chạy |  |
| marketing automation là gì | low | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| marketing automation là gì | low | 730 | 70 | chưa chạy | 0/0 | chưa chạy |  |
| marketing automation là gì | low | 781 | 75 | chưa chạy | 0/0 | chưa chạy |  |
| marketing automation là gì | low | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| marketing automation là gì | low | 908 | 95 | chưa chạy | 0/1 | chưa chạy |  |
| marketing automation là gì | low | 1380 | 95 | chưa chạy | 0/0 | chưa chạy |  |
| marketing automation là gì | low | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| marketing automation là gì | low | 1090 | 80 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| marketing automation là gì | low | 1798 | 90 | chưa chạy | 0/1 | chưa chạy |  |
| marketing automation là gì | low | 1712 | 95 | chưa chạy | 0/2 | chưa chạy |  |
| so sánh phần mềm marketing automation cho doanh nghiệp vừa và nhỏ | low | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| so sánh phần mềm marketing automation cho doanh nghiệp vừa và nhỏ | low | 919 | 75 | chưa chạy | 0/0 | chưa chạy |  |
| hướng dẫn thiết lập quy trình email tự động chăm sóc khách hàng | low | - | - | - | - | - | Bài chưa có phiên bản có nội dung. |
| hướng dẫn thiết lập quy trình email tự động chăm sóc khách hàng | low | 590 | 75 | chưa chạy | 0/0 | chưa chạy |  |
| crm là gì và có cần cho doanh nghiệp nhỏ | low | 1594 | 80 | chưa chạy | 0/4 | chưa chạy |  |
| học bổng toàn phần | low | 912 | 85 | chưa chạy | 0/0 | chưa chạy | không có nguồn |
| cách viết thư xin học bổng | low | 1527 | 75 | chưa chạy | 0/1 | chưa chạy |  |
