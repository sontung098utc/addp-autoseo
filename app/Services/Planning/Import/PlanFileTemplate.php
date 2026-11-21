<?php

namespace App\Services\Planning\Import;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * FILE MẪU nhiều sheet để người dùng tải về, điền rồi nạp lại ở "Nhập kế hoạch từ file":
 *  1. "Bài viết": MỘT DÒNG = MỘT BÀI. Các nhóm cột (có màu): từ khóa và phân loại, chỉ dẫn viết (đối tượng, giọng văn, độ dài, cấu trúc, câu hỏi, nguồn, liên kết nội bộ...), số liệu và lịch, chiến dịch.
 *  2. "Chiến dịch": mô tả và ngày của từng chiến dịch, nối với sheet 1 qua cột "Tên chiến dịch".
 *  3. "Hướng dẫn": từng cột có bắt buộc không, ý nghĩa, giá trị chấp nhận và hệ thống DÙNG NÓ ĐỂ LÀM GÌ.
 *  4. "Ví dụ đã điền": 3 bài mẫu điền đủ để xem cách viết (nằm riêng nên không phải xóa dòng ví dụ khỏi sheet 1).
 * Tiêu đề cột là các tên mà bộ nhận diện cột hiểu chắc chắn (có test vòng tròn), và recognize() nhận ra file mẫu để đọc TRỰC TIẾP, không tốn AI.
 */
final class PlanFileTemplate
{
    public const MAIN = 'Bài viết';

    public const CAMPAIGN = 'Chiến dịch';

    /** [nhóm, tiêu đề cột, bắt buộc?, ý nghĩa, giá trị chấp nhận, hệ thống dùng để làm gì, ví dụ 1, ví dụ 2, ví dụ 3] */
    public const COLUMNS = [
        ['Từ khóa & phân loại', 'Từ khóa chính', 'Bắt buộc', 'Từ khóa/chủ đề mà MỘT bài nhắm tới. Mỗi dòng một bài; không trùng nhau và không trùng bài đã có trên website.', 'Chữ, tối đa 190 ký tự', 'Quyết định chủ đề bài; chống tạo trùng bài.', 'người tiểu đường nên ăn gì', 'thực đơn cho người tăng huyết áp', 'xét nghiệm HbA1c là gì'],
        ['Từ khóa & phân loại', 'Từ khóa phụ', 'Nên có', 'Các từ khóa liên quan bài nên phủ trong nội dung.', 'Ngăn cách bằng dấu phẩy hoặc chấm phẩy; tối đa 8', 'Người viết đưa vào bài; bộ chấm kiểm có đủ không.', 'chế độ ăn tiểu đường, thực phẩm hạ đường huyết', 'ăn giảm muối, thực đơn 7 ngày', 'chỉ số HbA1c, đường huyết trung bình'],
        ['Từ khóa & phân loại', 'Ý định tìm kiếm', 'Nên có', 'Người tìm đang muốn gì.', 'thông tin | thương mại | giao dịch | điều hướng', 'Quyết định khung bài (hướng dẫn, so sánh, mua hàng...).', 'thông tin', 'thông tin', 'thông tin'],
        ['Từ khóa & phân loại', 'Cụm chủ đề', 'Không', 'Nhóm/trụ cột nội dung mà bài thuộc về.', 'Chữ tự do', 'Gom bài liên quan, gợi ý liên kết nội bộ.', 'Dinh dưỡng bệnh lý', 'Dinh dưỡng bệnh lý', 'Xét nghiệm và chỉ số'],
        ['Từ khóa & phân loại', 'Loại bài', 'Không', 'Dạng bài.', 'trụ cột | hướng dẫn | so sánh | danh sách | hỏi đáp | tin tức | bài sản phẩm', 'Vào chỉ dẫn để người viết chọn khung phù hợp.', 'trụ cột', 'hướng dẫn', 'hỏi đáp'],
        ['Từ khóa & phân loại', 'Tiêu đề gợi ý', 'Không', 'Tên bài dự kiến (đội AI có thể sửa cho hợp SEO).', 'Chữ tự do', 'Vào chỉ dẫn làm gợi ý tiêu đề.', 'Người tiểu đường nên ăn gì mỗi ngày?', 'Thực đơn mẫu cho người tăng huyết áp', 'HbA1c là gì? Chỉ số bao nhiêu là bình thường'],
        ['Chỉ dẫn viết', 'Đối tượng đọc', 'Nên có', 'Ai sẽ đọc: tuổi, hoàn cảnh, mức hiểu biết.', 'Chữ tự do', 'Người viết chọn giọng, mức giải thích thuật ngữ, ví dụ.', 'Người trên 50 tuổi mới được chẩn đoán tiểu đường', 'Người bị tăng huyết áp và người nhà nấu ăn', 'Người vừa nhận kết quả xét nghiệm'],
        ['Chỉ dẫn viết', 'Góc tiếp cận / điều cần nhấn', 'Không', 'Thông điệp chính, điều cần nhấn mạnh hoặc góc viết riêng.', 'Chữ tự do', 'Người viết bám theo để bài có góc nhìn riêng, không chung chung.', 'Ăn theo bữa quen thuộc của người Việt, không kiêng khem quá mức', 'Giảm muối từ gia vị chấm và nước dùng', 'Giải thích dễ hiểu, không gây hoang mang'],
        ['Chỉ dẫn viết', 'Giọng văn', 'Không', 'Phong cách viết.', 'gần gũi | chuyên môn | nghiêm túc | trấn an', 'Người viết và Biên tập bám theo.', 'gần gũi, trấn an', 'gần gũi', 'chuyên môn nhưng dễ hiểu'],
        ['Chỉ dẫn viết', 'Độ dài (số từ)', 'Nên có', 'Độ dài mong muốn của bài.', 'Một số (1500) hoặc khoảng (1200-1500); từ 50 đến 20000', 'ĐO ĐƯỢC: bộ chấm kiểm độ dài và yêu cầu sửa nếu lệch.', '1500', '1200-1500', '1000'],
        ['Chỉ dẫn viết', 'Số bảng', 'Không', 'Số bảng bài cần có (bảng so sánh, thực đơn, ngưỡng).', 'Số nguyên 0-10', 'ĐO ĐƯỢC: bộ chấm kiểm đúng số bảng.', '1', '1', '0'],
        ['Chỉ dẫn viết', 'Số ảnh tối đa', 'Không', 'Số ảnh tối đa trong bài.', 'Số nguyên 0-10', 'ĐO ĐƯỢC: bộ chấm kiểm không vượt số ảnh.', '3', '2', '1'],
        ['Chỉ dẫn viết', 'Số mục H2 tối thiểu', 'Không', 'Số đề mục lớn tối thiểu.', 'Số nguyên 0-20', 'ĐO ĐƯỢC: bộ chấm kiểm có đủ mục H2.', '6', '5', '4'],
        ['Chỉ dẫn viết', 'Có mục hỏi đáp (FAQ)', 'Không', 'Bài có mục câu hỏi thường gặp không.', 'Có | Không', 'Vào chỉ dẫn; người viết thêm hoặc không thêm mục FAQ.', 'Có', 'Không', 'Có'],
        ['Chỉ dẫn viết', 'Câu hỏi bài cần trả lời', 'Nên có', 'Các câu hỏi cụ thể người đọc đặt ra mà bài PHẢI trả lời.', 'Ngăn cách bằng chấm phẩy hoặc xuống dòng', 'Người viết dựng mục và câu trả lời cho từng câu hỏi.', 'Người tiểu đường ăn cơm trắng được không; Uống sữa thế nào', 'Mỗi ngày ăn bao nhiêu muối; Có cần kiêng nước mắm không', 'HbA1c bao nhiêu là tiền tiểu đường; Bao lâu nên xét nghiệm lại'],
        ['Chỉ dẫn viết', 'Nguồn nên dùng', 'Nên có (bài sức khỏe)', 'Tên/URL nguồn, hướng dẫn hoặc tổ chức nên dùng làm căn cứ. Hệ thống KHÔNG tự tải link; nguồn vẫn phải qua kiểm duyệt.', 'Tên hoặc URL, ngăn cách bằng chấm phẩy', 'Người viết/Người nghiên cứu ưu tiên tìm và trích đúng nguồn này.', 'Bộ Y tế; Hiệp hội Đái tháo đường', 'Viện Dinh dưỡng Quốc gia', 'Hướng dẫn chẩn đoán của hội chuyên khoa'],
        ['Chỉ dẫn viết', 'Liên kết nội bộ nên có', 'Không', 'Bài hoặc URL của chính website nên được liên kết tới.', 'Tên bài hoặc URL, ngăn cách bằng chấm phẩy', 'Vào chỉ dẫn để người viết đặt liên kết nội bộ.', 'thực đơn cho người tiểu đường; chỉ số đường huyết', 'ăn giảm muối', 'tiền tiểu đường'],
        ['Chỉ dẫn viết', 'Điều cần tránh', 'Không', 'Điều không được nói hoặc không nên đề cập, từ cấm riêng của bài.', 'Chữ tự do', 'Người viết tránh; không thay quy tắc an toàn chung của hệ thống.', 'Không khuyên ngừng thuốc; không nêu liều thuốc', 'Không nhắc tên nhãn hàng', 'Không tự chẩn đoán cho người đọc'],
        ['Chỉ dẫn viết', 'Đối thủ / bài tham khảo', 'Không', 'URL hoặc tên bài đối thủ để tham khảo góc viết. Hệ thống KHÔNG tự tải; không sao chép.', 'URL hoặc tên, ngăn cách bằng chấm phẩy', 'Vào chỉ dẫn làm tham khảo góc viết.', 'https://example.com/an-gi-khi-bi-tieu-duong', '', ''],
        ['Chỉ dẫn viết', 'CTA / sản phẩm liên quan', 'Không', 'Lời kêu gọi hành động hoặc sản phẩm/dịch vụ cần nhắc.', 'Chữ tự do', 'Người viết đặt CTA tự nhiên ở cuối bài; không được hứa kết quả.', 'Đặt lịch tư vấn dinh dưỡng', '', 'Đăng ký nhận thực đơn mẫu'],
        ['Chỉ dẫn viết', 'Cần chuyên gia duyệt', 'Nên có (bài sức khỏe)', 'Bài có cần người có chuyên môn duyệt kỹ trước khi dùng không.', 'Có | Không', 'Vào chỉ dẫn; bài y tế rủi ro cao vẫn luôn chờ người có chuyên môn.', 'Có', 'Có', 'Có'],
        ['Số liệu & lịch', 'Lượng tìm kiếm/tháng', 'Không', 'Lượng tìm của từ khóa chính nếu có số liệu thật; để TRỐNG nếu chưa có (đừng điền 0).', 'Số nguyên: 5400 hoặc 5.400', 'Điền vào kho từ khóa (chỉ chỗ còn trống, không ghi đè số liệu nguồn khác).', '5400', '2900', '8100'],
        ['Số liệu & lịch', 'Độ khó từ khóa', 'Không', 'Độ khó của từ khóa chính (KD) nếu có.', 'Số 0-100', 'Điền vào kho từ khóa (chỉ chỗ còn trống).', '28', '35', '42'],
        ['Số liệu & lịch', 'CPC (VNĐ)', 'Không', 'Giá thầu CPC ước tính của từ khóa chính (VNĐ), nếu có.', 'Số nguyên: 4500 hoặc 4.500', 'Điền vào kho từ khóa (chỉ chỗ còn trống).', '4500', '3200', '6000'],
        ['Số liệu & lịch', 'Mức cạnh tranh', 'Không', 'Mức cạnh tranh quảng cáo của từ khóa chính.', 'Số 0-1 (0,35), số 0-100 (35) hoặc thấp | trung bình | cao', 'Điền vào kho từ khóa (chỉ chỗ còn trống).', '0,35', 'thấp', 'cao'],
        ['Số liệu & lịch', 'Ngày dự kiến đăng', 'Không', 'Ngày muốn đăng (chỉ là dự kiến; bài vẫn chờ người duyệt).', 'Ngày/tháng/năm: 25/12/2026', 'Xếp lịch dự kiến, tránh ngày đã đủ bài.', '25/12/2026', '28/12/2026', '30/12/2026'],
        ['Số liệu & lịch', 'Mức ưu tiên', 'Không', 'Bài nào viết trước.', 'cao | trung bình | thấp, hoặc số', 'Xếp thứ tự viết trong chiến dịch.', 'cao', 'trung bình', 'cao'],
        ['Chiến dịch', 'Tên chiến dịch', 'Nên có', 'Gom các dòng cùng tên thành một chiến dịch. Mô tả và ngày của chiến dịch điền ở sheet "Chiến dịch". Để trống thì chọn chiến dịch khi nhập.', 'Chữ tự do, khớp tên ở sheet "Chiến dịch"', 'Tạo chiến dịch (tạm dừng) và đưa bài vào.', 'Dinh dưỡng mùa đông', 'Dinh dưỡng mùa đông', 'Dinh dưỡng mùa đông'],
        ['Chiến dịch', 'Ghi chú khác', 'Không', 'Mọi yêu cầu khác chưa có cột riêng.', 'Chữ tự do, tối đa 600 ký tự', 'Vào chỉ dẫn của bài (tổng chỉ dẫn tối đa 1500 ký tự).', '', '', ''],
    ];

    public const CAMPAIGN_COLUMNS = [
        ['Tên chiến dịch', 'Bắt buộc', 'Trùng tên với cột "Tên chiến dịch" ở sheet "Bài viết".', 'Dinh dưỡng mùa đông'],
        ['Mô tả chiến dịch', 'Không', 'Mục tiêu của chiến dịch.', 'Tăng nội dung dinh dưỡng cho bệnh mạn tính trong mùa lạnh'],
        ['Chiến dịch bắt đầu', 'Không', 'Ngày bắt đầu (ngày/tháng/năm).', '01/12/2026'],
        ['Chiến dịch kết thúc', 'Không', 'Ngày kết thúc (ngày/tháng/năm).', '28/02/2027'],
    ];

    /** @return list<string> */
    public static function headers(): array
    {
        return array_column(self::COLUMNS, 1);
    }

    private static function groupColor(string $group): string
    {
        return match ($group) {
            'Từ khóa & phân loại' => Color::rgb(15, 118, 110),
            'Chỉ dẫn viết' => Color::rgb(79, 70, 229),
            'Số liệu & lịch' => Color::rgb(180, 83, 9),
            default => Color::rgb(71, 85, 105),
        };
    }

    public static function xlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plan_tpl_');
        $opts = new Options;
        $opts->setColumnWidthForRange(24, 1, count(self::COLUMNS));
        $writer = new Writer($opts);
        $writer->openToFile($path);
        $plain = (new Style)->setShouldWrapText();
        $white = Color::WHITE;

        // 1) Bài viết: chỉ tiêu đề (màu theo nhóm); 3 bài mẫu nằm ở sheet "Ví dụ đã điền"
        $writer->getCurrentSheet()->setName(self::MAIN);
        $writer->addRow(new Row(array_map(fn ($c) => \OpenSpout\Common\Entity\Cell::fromValue($c[1], (new Style)->setFontBold()->setFontColor($white)->setBackgroundColor(self::groupColor($c[0]))->setShouldWrapText()), self::COLUMNS)));

        // 2) Chiến dịch
        $camp = $writer->addNewSheetAndMakeItCurrent();
        $camp->setName(self::CAMPAIGN);
        $writer->addRow(new Row(array_map(fn ($c) => \OpenSpout\Common\Entity\Cell::fromValue($c[0], (new Style)->setFontBold()->setFontColor($white)->setBackgroundColor(Color::rgb(71, 85, 105))), self::CAMPAIGN_COLUMNS)));
        $writer->addRow(Row::fromValues(array_map(fn ($c) => $c[3], self::CAMPAIGN_COLUMNS)));

        // 3) Hướng dẫn
        $guide = $writer->addNewSheetAndMakeItCurrent();
        $guide->setName('Hướng dẫn');
        $guide->setColumnWidth(30, 1);
        $guide->setColumnWidth(24, 2);
        $guide->setColumnWidth(60, 3);
        $guide->setColumnWidth(48, 4);
        $guide->setColumnWidth(48, 5);
        $head = (new Style)->setFontBold()->setFontColor($white)->setBackgroundColor(Color::rgb(71, 85, 105));
        $writer->addRow(Row::fromValues(['Cột (sheet "Bài viết")', 'Bắt buộc?', 'Ý nghĩa', 'Giá trị chấp nhận', 'Hệ thống dùng để làm gì'], $head));
        foreach (self::COLUMNS as $c) {
            $writer->addRow(Row::fromValues([$c[1], $c[2], $c[3], $c[4], $c[5]], $plain));
        }
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['Cách dùng: điền sheet "Bài viết" (một dòng một bài) và sheet "Chiến dịch" (nếu muốn mô tả/ngày chiến dịch), rồi tải file lên ở "Nhập kế hoạch từ file". Chỉ cột "Từ khóa chính" là bắt buộc; càng nhiều thông tin viết bài thì bài càng sát ý. Đổi thứ tự cột hoặc bớt cột không dùng được, nhưng đừng đổi tên cột hay tên sheet.'], $plain));
        $writer->addRow(Row::fromValues(['Cột có ghi "ĐO ĐƯỢC": bộ chấm của hệ thống sẽ kiểm bài viết ra có đúng yêu cầu đó không và yêu cầu sửa nếu chưa đạt.'], $plain));
        $writer->addRow(Row::fromValues(['Hệ thống chỉ lưu có cấu trúc: từ khóa chính/phụ, ý định, cụm chủ đề, ưu tiên, chiến dịch, ngày, lượng tìm kiếm, độ khó. Các cột "Chỉ dẫn viết" được ghép thành chỉ dẫn của bài (tối đa 1500 ký tự).'], $plain));

        // 4) Ví dụ đã điền
        $ex = $writer->addNewSheetAndMakeItCurrent();
        $ex->setName('Ví dụ đã điền');
        $writer->addRow(Row::fromValues(self::headers(), $head));
        foreach ([6, 7, 8] as $i) {
            $writer->addRow(Row::fromValues(array_map('strval', array_column(self::COLUMNS, $i)), $plain));
        }
        $writer->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** CSV UTF-8 có BOM (Excel mở đúng tiếng Việt): dòng tiêu đề + một dòng VÍ DỤ ghi rõ "xóa dòng này" (bộ nhập tự bỏ qua nếu quên xóa). */
    public static function csv(): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, self::headers());
        $row = array_map('strval', array_column(self::COLUMNS, 6));
        $iNote = array_search('Ghi chú khác', self::headers(), true);
        $row[$iNote] = '(VÍ DỤ, xóa dòng này)';
        fputcsv($fh, $row);
        rewind($fh);

        return "\xEF\xBB\xBF".stream_get_contents($fh);
    }

    /**
     * Nhận ra file làm đúng theo mẫu (sheet "Bài viết" hoặc sheet đầu của CSV có đủ các tên cột mẫu): trả cấu trúc để đọc TRỰC TIẾP, không cần AI. Null nếu không phải mẫu.
     *
     * @param  list<array{name:string, rows:list<list<string>>}>  $sheets
     * @return array{sheet:string, header_row:int, links:list<array<string,mixed>>}|null
     */
    public static function recognize(array $sheets): ?array
    {
        $main = null;
        foreach ($sheets as $s) {
            if ($s['name'] === self::MAIN) {
                $main = $s;
            }
        }
        $main ??= $sheets[0] ?? null;
        if ($main === null) {
            return null;
        }
        $head = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $main['rows'][0] ?? []);
        $hit = count(array_filter(self::headers(), fn ($h) => in_array(mb_strtolower($h), $head, true)));
        if ($hit < 12 || ! in_array(mb_strtolower('Từ khóa chính'), $head, true)) {
            return null;
        }
        $links = [];
        $campSheet = null;
        foreach ($sheets as $s) {
            if ($s['name'] === self::CAMPAIGN) {
                $campSheet = $s;
            }
        }
        $from = array_search(mb_strtolower('Tên chiến dịch'), $head, true);
        if ($campSheet !== null && $from !== false && count(array_filter(array_slice($campSheet['rows'], 1), fn ($r) => trim((string) ($r[0] ?? '')) !== '')) > 0) {
            $links[] = ['from_col' => PlanFileFields::colLetter((int) $from), 'sheet' => self::CAMPAIGN, 'header_row' => 1, 'key_col' => 'A',
                'fields' => [['col' => 'B', 'field' => 'campaign_description'], ['col' => 'C', 'field' => 'campaign_start'], ['col' => 'D', 'field' => 'campaign_end']]];
        }

        return ['sheet' => $main['name'], 'header_row' => 1, 'links' => $links];
    }
}
