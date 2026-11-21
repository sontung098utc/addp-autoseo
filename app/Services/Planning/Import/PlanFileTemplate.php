<?php

namespace App\Services\Planning\Import;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * FILE MẪU để người dùng tải về, điền rồi nạp lại ở "Nhập kế hoạch từ file". Tiêu đề cột là các tên mà bộ nhận diện cột hiểu chắc chắn (có test vòng tròn:
 * tải mẫu → đọc lại → mọi cột ánh xạ đúng trường), nên file làm theo mẫu không cần AI đoán cột.
 * Hai sheet: "Kế hoạch" (dòng tiêu đề + 2 dòng VÍ DỤ ghi rõ "xóa dòng này") và "Hướng dẫn" (ý nghĩa, giá trị chấp nhận từng cột).
 */
final class PlanFileTemplate
{
    /** [tiêu đề cột, bắt buộc?, ý nghĩa, giá trị chấp nhận, ví dụ 1, ví dụ 2] */
    public const COLUMNS = [
        ['Từ khóa chính', 'Bắt buộc', 'Từ khóa/chủ đề chính mà MỘT bài nhắm tới. Mỗi dòng là một bài; không trùng nhau.', 'Chữ, tối đa 190 ký tự', 'người tiểu đường nên ăn gì', 'thực đơn cho người tăng huyết áp'],
        ['Từ khóa phụ', 'Không', 'Các từ khóa liên quan bài nên phủ.', 'Ngăn cách bằng dấu phẩy hoặc chấm phẩy', 'chế độ ăn tiểu đường, thực phẩm hạ đường huyết', 'ăn giảm muối, thực đơn 7 ngày'],
        ['Ý định tìm kiếm', 'Không', 'Người tìm đang muốn gì.', 'thông tin | thương mại | giao dịch | điều hướng', 'thông tin', 'thông tin'],
        ['Cụm chủ đề', 'Không', 'Nhóm/trụ cột nội dung mà bài thuộc về, để gom bài liên quan.', 'Chữ tự do', 'Dinh dưỡng bệnh lý', 'Dinh dưỡng bệnh lý'],
        ['Tiêu đề gợi ý', 'Không', 'Tên bài dự kiến (đội AI có thể sửa).', 'Chữ tự do', 'Người tiểu đường nên ăn gì mỗi ngày?', 'Thực đơn mẫu cho người tăng huyết áp'],
        ['Ghi chú cho đội AI', 'Không', 'Yêu cầu riêng cho bài: đối tượng, góc nhìn, điều cần nhấn hoặc tránh. Dòng nào bắt đầu bằng "(VÍ DỤ" là dòng mẫu, hãy xóa.', 'Chữ tự do, tối đa 1500 ký tự', '(VÍ DỤ, xóa dòng này) Viết cho người trên 50 tuổi, giọng gần gũi', '(VÍ DỤ, xóa dòng này) Có bảng thực đơn 3 bữa'],
        ['Lượng tìm kiếm/tháng', 'Không', 'Lượng tìm của từ khóa chính nếu bạn có số liệu thật; để trống nếu chưa có (không điền 0).', 'Số nguyên, ví dụ 5400 hoặc 5.400', '5400', '2900'],
        ['Ngày dự kiến đăng', 'Không', 'Ngày muốn đăng bài (chỉ là dự kiến, bài vẫn chờ người duyệt).', 'Ngày/tháng/năm, ví dụ 25/12/2026', '25/12/2026', '28/12/2026'],
        ['Mức ưu tiên', 'Không', 'Bài nào viết trước.', 'cao | trung bình | thấp, hoặc số 1 (cao nhất) đến 5', 'cao', 'trung bình'],
        ['Tên chiến dịch', 'Không', 'Gom các dòng cùng tên thành một chiến dịch; để trống thì chọn chiến dịch khi nhập.', 'Chữ tự do', 'Dinh dưỡng mùa đông', 'Dinh dưỡng mùa đông'],
        ['Mô tả chiến dịch', 'Không', 'Mục tiêu của chiến dịch (lấy từ dòng đầu tiên của chiến dịch).', 'Chữ tự do', 'Tăng nội dung dinh dưỡng bệnh mạn tính', 'Tăng nội dung dinh dưỡng bệnh mạn tính'],
    ];

    /** @return list<string> */
    public static function headers(): array
    {
        return array_column(self::COLUMNS, 0);
    }

    public static function xlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plan_tpl_');
        $writer = new Writer;
        $writer->openToFile($path);
        $head = (new Style)->setFontBold();
        $writer->getCurrentSheet()->setName('Kế hoạch');
        $writer->addRow(Row::fromValues(self::headers(), $head));
        foreach ([4, 5] as $i) {
            $writer->addRow(Row::fromValues(array_column(self::COLUMNS, $i)));
        }
        $writer->addNewSheetAndMakeItCurrent()->setName('Hướng dẫn');
        $writer->addRow(Row::fromValues(['Cột', 'Bắt buộc?', 'Ý nghĩa', 'Giá trị chấp nhận', 'Ví dụ'], $head));
        foreach (self::COLUMNS as $c) {
            $writer->addRow(Row::fromValues([$c[0], $c[1], $c[2], $c[3], $c[4]]));
        }
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Lưu ý: giữ nguyên dòng tiêu đề ở sheet "Kế hoạch" (đổi thứ tự cột được, bớt cột không dùng được). Xóa 2 dòng ví dụ, rồi tải file lên ở "Nhập kế hoạch từ file". Bạn sẽ xem trước toàn bộ trước khi tạo bài.']));
        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** CSV UTF-8 có BOM (Excel mở đúng tiếng Việt). */
    public static function csv(): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, self::headers());
        foreach ([4, 5] as $i) {
            fputcsv($fh, array_column(self::COLUMNS, $i));
        }
        rewind($fh);

        return "\xEF\xBB\xBF".stream_get_contents($fh);
    }
}
