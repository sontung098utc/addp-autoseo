<div class="ss-grid ss-text-sm">
    <div class="ss-card"><div class="ss-card__h">Điểm ưu tiên</div><div class="ss-card__b" style="display:grid;gap:.5rem">
        <div class="ss-mono">{{ $formula }}</div>
        <div class="ss-muted">V = log10(volume + 1) ÷ log10(volume lớn nhất + 1) · D = độ khó (0–100) · O = số đối thủ xếp hạng ÷ tổng số đối thủ. Trọng số: 50 điểm volume, 30 điểm độ khó dễ, 20 điểm mức chồng lấn. Điểm chỉ để xếp thứ tự, không thay phán đoán của người làm nội dung.</div>
    </div></div>
    <div class="ss-card"><div class="ss-card__h">Nguồn dữ liệu và phạm vi</div><div class="ss-card__b"><ul style="list-style:disc;padding-left:1.25rem;display:grid;gap:.25rem">
        <li>Đối thủ do người dùng nhập (tên miền công khai); hệ thống không truy cập website đối thủ. Thứ hạng đến từ API từ khóa trả phí.</li>
        <li>Dữ liệu ngoài chỉ để tham khảo; hệ thống không tự thêm, tự viết hay tự đăng.</li>
        <li>Tên miền được chuẩn hóa; từ chối IP, localhost, tên miền nội bộ, trùng, tên miền của chính website, và quá {{ $maxCompetitors }} đối thủ.</li>
        <li>Quyền: xem, thêm/xóa đối thủ, làm mới: quản duyệt trở lên; thêm vào kế hoạch cần thêm quyền tạo bài.</li>
    </ul></div></div>
    <div class="ss-card"><div class="ss-card__h">Chi phí, hạn mức, làm mới</div><div class="ss-card__b"><ul style="list-style:disc;padding-left:1.25rem;display:grid;gap:.25rem">
        <li>Mỗi lần làm mới tốn phí: hiện chi phí ước tính và hạn mức còn lại, bắt buộc xác nhận. Vượt hạn mức thì nút bị vô hiệu kèm lý do.</li>
        <li>Không làm mới tự động. API lỗi: dừng, không trừ hạn mức, bản cũ luôn gắn nhãn thời điểm.</li>
        <li>Mỗi lần làm mới ghi lại: ai bấm, lúc nào, số dòng, chi phí ước tính.</li>
    </ul></div></div>
    <div class="ss-card"><div class="ss-card__h">Khoảng trống và trùng từ khóa</div><div class="ss-card__b"><ul style="list-style:disc;padding-left:1.25rem;display:grid;gap:.25rem">
        <li>Từ khóa khớp bài hiện có (chuẩn hóa chữ thường, bỏ khoảng trắng thừa) được đánh dấu "Đã có bài" / "Đã lên kế hoạch", không chấm điểm và bị bỏ qua khi thêm vào kế hoạch. Chưa khớp theo ngữ nghĩa; người duyệt kiểm tra.</li>
        <li>Mức trùng = từ khóa đối thủ xếp hạng mà website đã có bài ÷ từ khóa đối thủ xếp hạng.</li>
        <li>"Thêm vào kế hoạch" tạo bài thật ở trạng thái Đã lên kế hoạch, báo kết quả từng từ khóa; thành công một phần được phép.</li>
    </ul></div></div>
</div>
