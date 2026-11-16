<div style="max-height:60vh;overflow:auto">
    <p><b>Điểm ưu tiên</b> <span class="ss-mono">{{ $formula }}</span><br>V = log10(volume + 1) ÷ log10(volume lớn nhất + 1) · D = độ khó (0–100) · O = số đối thủ xếp hạng ÷ tổng số đối thủ. Trọng số: 50 điểm volume, 30 điểm độ khó dễ, 20 điểm mức chồng lấn. Chỉ để xếp thứ tự.</p>
    <b>Nguồn dữ liệu và phạm vi</b>
    <ul>
        <li>Đối thủ do người dùng nhập (tên miền công khai); hệ thống không truy cập website đối thủ. Thứ hạng đến từ API từ khóa trả phí.</li>
        <li>Dữ liệu ngoài chỉ để tham khảo; hệ thống không tự thêm, tự viết hay tự đăng.</li>
        <li>Tên miền được chuẩn hóa; từ chối IP, localhost, tên miền nội bộ, trùng, tên miền của chính website, và quá {{ $maxCompetitors }} đối thủ.</li>
        <li>Quyền: xem, thêm/xóa đối thủ, làm mới: quản duyệt trở lên; thêm vào kế hoạch cần thêm quyền tạo bài.</li>
    </ul>
    <b>Chi phí, hạn mức, làm mới</b>
    <ul>
        <li>Mỗi lần làm mới tốn phí: hiện chi phí ước tính và hạn mức còn lại, bắt buộc xác nhận. Vượt hạn mức thì nút bị vô hiệu kèm lý do.</li>
        <li>Không làm mới tự động. API lỗi: dừng, không trừ hạn mức, bản cũ luôn gắn nhãn thời điểm.</li>
        <li>Mỗi lần làm mới ghi lại: ai bấm, lúc nào, số dòng, chi phí ước tính.</li>
    </ul>
    <b>Khoảng trống và trùng từ khóa</b>
    <ul>
        <li>Từ khóa khớp bài hiện có được đánh dấu "Đã có bài" / "Đã lên kế hoạch", không chấm điểm và bị bỏ qua khi thêm vào kế hoạch. Chưa khớp theo ngữ nghĩa; người duyệt kiểm tra.</li>
        <li>Mức trùng = từ khóa đối thủ xếp hạng mà website đã có bài ÷ từ khóa đối thủ xếp hạng.</li>
        <li>"Thêm vào kế hoạch" tạo bài thật ở trạng thái Đã lên kế hoạch, báo kết quả từng từ khóa; thành công một phần được phép.</li>
    </ul>
</div>
