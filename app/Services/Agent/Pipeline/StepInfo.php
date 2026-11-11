<?php

namespace App\Services\Agent\Pipeline;

/**
 * Chữ HIỂN THỊ cho từng bước của luồng (tên gọn, mô tả một dòng, vì sao có bước, luật áp dụng). Chỉ là dữ liệu hiển thị:
 * không đổi hành vi chạy. Bước nào chưa khai thì dùng chữ chung theo mã bước, cuối cùng là nhãn trong JobTypeRegistry.
 */
final class StepInfo
{
    /** 'loại.bước' => [tên gọn|null, mô tả một dòng, vì sao có bước, luật/rubric áp dụng|null] */
    private const BY_STEP = [
        'article_write.guard' => [null, 'Kiểm dừng khẩn cấp và website còn hoạt động.', 'Không tốn chi phí khi hệ thống đang bị khóa.', 'Dừng khẩn cấp, trạng thái website.'],
        'article_write.risk' => [null, 'Gắn hạng rủi ro cho chủ đề (sức khỏe, tài chính, pháp lý).', 'Chủ đề rủi ro cao cần người có chuyên môn xem lại.', 'RiskClassifier: luật từ khóa, không dùng AI.'],
        'article_write.brief' => [null, 'Lập dàn ý từ từ khóa và ý định tìm kiếm.', 'Dàn ý tốt giúp bài đúng ý và ít phải sửa.', 'Playbook dàn ý đang bật.'],
        'article_write.research' => [null, 'Nạp kiến thức đã duyệt, ảnh, liên kết nội bộ và nguồn trích.', 'Bài viết dựa trên dữ liệu của doanh nghiệp, không bịa.', 'Chỉ dùng kiến thức đã duyệt trong Kho trí tuệ.'],
        'article_write.write' => [null, 'Viết bản nháp theo dàn ý.', 'Đây là bước tạo nội dung chính.', 'HTML luôn qua bộ làm sạch XSS.'],
        'article_write.images' => [null, 'Tạo hoặc chọn ảnh cho bài.', 'Bài có ảnh minh họa dễ đọc hơn.', 'Bỏ qua nếu đã có ảnh bìa hoặc hết trần chi phí ảnh.'],
        'article_write.grade' => [null, 'Chấm bản nháp bằng luật chất lượng.', 'AI không được tự chấm bài của chính nó.', 'ContentQualityChecker: lỗi P0 chặn, P1 P2 cảnh báo.'],
        'article_write.repair' => [null, 'Sửa các lỗi chặn rồi chấm lại, tối đa 3 vòng.', 'Tự khắc phục lỗi để người duyệt ít phải làm lại.', 'Dừng khi hết vòng, hết ngân sách hoặc điểm không tăng.'],
        'article_write.handoff' => [null, 'Lưu bản nháp và chuyển cho bạn duyệt.', 'Hệ thống không bao giờ tự xuất bản.', 'Bậc tin cậy T1: chỉ tạo bản nháp.'],
        'article_refresh.guard' => [null, 'Kiểm điều kiện an toàn trước khi sửa bài cũ.', 'Tránh tốn chi phí khi hệ thống đang bị khóa.', null],
        'article_refresh.grade_old' => [null, 'Chấm bản hiện tại để biết chỗ yếu.', 'Có điểm "trước" để so sánh với bản mới.', 'Cùng luật chất lượng với bài mới.'],
        'article_refresh.scope' => [null, 'Chọn mức sửa: tiêu đề và meta, vài mục, hoặc viết lại.', 'Sửa đúng chỗ cần thì rẻ và ít rủi ro hơn.', 'Ba mức sửa (a) (b) (c).'],
        'article_refresh.meta' => [null, 'Sinh lại tiêu đề và mô tả meta.', 'Cách rẻ nhất để cải thiện bài tụt hạng.', 'Bậc model rẻ.'],
        'article_refresh.section' => [null, 'Sửa riêng các mục có lỗi.', 'Giữ phần tốt của bài, chỉ gửi mục cần sửa.', 'Bậc model chuẩn.'],
        'article_refresh.rewrite' => [null, 'Viết lại cả bài.', 'Dùng khi bài yếu toàn diện hoặc AI không sửa từng phần được.', null],
        'article_refresh.grade' => [null, 'Chấm lại bản mới.', 'Chứng minh bản mới tốt hơn bản cũ.', 'ContentQualityChecker.'],
        'article_refresh.handoff' => [null, 'Chuyển bản mới để bạn so sánh trước và sau.', 'Bạn quyết định có thay bài đang đăng hay không.', null],
        'site_audit.guard' => [null, 'Kiểm điều kiện an toàn trước khi quét.', 'Không quét khi website bị khóa.', null],
        'site_audit.crawl' => [null, 'Quét các trang của website.', 'Cần dữ liệu thật về từng trang để chấm.', 'Chỉ đọc, không sửa website.'],
        'site_audit.score' => [null, 'Nhóm lỗi SEO và chấm điểm.', 'Cho bạn thấy lỗi nào nặng nhất.', 'Luật audit cố định.'],
        'site_audit.suggest' => [null, 'Đề xuất bài nên làm mới, khớp URL với bài.', 'Biến lỗi thành việc cụ thể để bạn chọn.', null],
        'content_plan.guard' => [null, 'Kiểm điều kiện an toàn.', 'Tránh lập kế hoạch khi hệ thống bị khóa.', null],
        'content_plan.dedupe' => [null, 'Bỏ từ khóa đã có bài.', 'Không viết trùng bài đã có.', null],
        'content_plan.offtopic' => [null, 'Kiểm từ khóa lệch chủ đề doanh nghiệp.', 'Giữ nội dung đúng thương hiệu.', 'Dựa trên Hồ sơ doanh nghiệp.'],
        'content_plan.rank' => [null, 'Xếp thứ tự ưu tiên viết.', 'Bài quan trọng được viết trước.', 'Theo lượt tìm, khoảng trống và độ phủ cụm.'],
        'content_plan.create' => [null, 'Tạo bài ở trạng thái chờ viết sau khi bạn duyệt.', 'Bạn quyết định bài nào vào hàng chờ.', null],
        'brain_build.guard' => ['Kiểm cổng', 'Kiểm quyền, website hoạt động, dừng khẩn cấp và có AI.', 'Không tốn chi phí khi chưa đủ điều kiện.', null],
        'brain_build.select' => ['Chọn trang', 'Chọn trang từ sitemap hoặc địa chỉ bạn dán.', 'Chỉ đọc đúng phần cần dựng kho.', null],
        'brain_build.search' => ['Tìm nguồn web', 'Tìm nguồn ngoài và gắn nhãn tin cậy bằng luật cố định.', 'Bổ sung nguồn tham khảo bên ngoài.', 'Nhãn tin cậy do luật cố định, không do AI.'],
        'brain_build.source' => ['Đọc nguồn ngoài', 'Mỗi nguồn: tải an toàn, trích chữ, AI rút mục kiến thức.', 'Biến nguồn thô thành mục kiến thức dùng lại được.', 'Nguồn tin cậy tự nhận, nguồn chưa rõ chỉ đề xuất.'],
        'brain_build.page' => ['Đọc trang', 'Mỗi trang: tải an toàn, trích chữ, AI rút mục, xếp tầng.', 'Biến nội dung website thành kiến thức.', null],
        'brain_build.brand' => ['Hồ sơ doanh nghiệp', 'AI điền nháp các trường trống của Hồ sơ doanh nghiệp.', 'Hồ sơ đầy đủ giúp bài viết đúng giọng thương hiệu.', 'Chỉ điền trường trống, có căn cứ trang.'],
        'brain_build.suggest' => ['Gợi ý cho kho', 'Gợi ý khoảng trống, số liệu và nguồn từ bài gần đây.', 'Cho bạn biết kho còn thiếu gì.', null],
        'brain_build.gaps' => ['Lấp khoảng trống', 'Dựng kho từ khoảng trống kiến thức.', 'Tự bổ sung phần kho còn thiếu.', null],
        'market_research.guard' => [null, 'Kiểm điều kiện an toàn.', 'Tránh tốn chi phí khi hệ thống bị khóa.', null],
        'market_research.collect' => [null, 'Gom từ khóa từ kho và đối thủ, loại trùng.', 'Có danh sách gốc để đề xuất.', null],
        'market_research.suggest' => [null, 'Đề xuất từ khóa mới quanh hạt giống.', 'Tìm cơ hội bạn chưa nghĩ tới.', 'Bậc model rẻ.'],
        'market_research.cluster' => [null, 'Gom từ khóa thành cụm và gán ý định.', 'Mỗi cụm là một chủ đề bài viết.', 'Gom lô, chỉ từ chưa có cụm.'],
        'market_research.score' => [null, 'Tính điểm ưu tiên khoảng trống.', 'Chọn cụm đáng làm trước.', null],
        'market_research.save' => [null, 'Ghi đề xuất chờ bạn chấp nhận.', 'Bạn quyết định từ khóa nào vào kho.', null],
        'measure.connect' => [null, 'Kiểm kết nối Search Console.', 'Chưa nối thì không có số liệu thật để đo.', null],
        'measure.pull' => [null, 'Kéo số liệu từ Search Console.', 'Lấy lượt hiển thị, nhấp, thứ hạng.', null],
        'measure.match' => [null, 'Ghép số liệu với bài đã đăng.', 'Biết bài nào đang lên hay tụt.', null],
        'measure.detect' => [null, 'Phát hiện bài tụt hạng.', 'Tìm bài cần làm mới sớm.', 'Theo ngưỡng.'],
        'measure.suggest' => [null, 'Đề xuất làm mới bài tụt hạng.', 'Đưa kết quả đo ngược về kế hoạch.', null],
    ];

    /** Chữ chung theo mã bước khi chưa khai riêng. */
    private const GENERIC = [
        'guard' => 'Kiểm điều kiện an toàn trước khi chạy.',
        'grade' => 'Chấm chất lượng bằng luật.',
        'suggest' => 'Đề xuất để bạn chọn.',
        'handoff' => 'Chuyển kết quả cho bạn duyệt.',
        'score' => 'Chấm điểm và xếp thứ tự.',
    ];

    /** @return array{short:string, desc:string, why:string, rule:?string} */
    public static function for(string $job, string $step, string $label, string $kind): array
    {
        $row = self::BY_STEP[$job.'.'.$step] ?? null;
        $long = mb_strlen($label) > 28;
        $desc = $row[1] ?? self::GENERIC[$step] ?? ($long ? $label : 'Bước '.($kind === 'llm' ? 'dùng AI' : 'chạy theo luật').': '.mb_strtolower($label).'.');

        return [
            'short' => $row[0] ?? $label,
            'desc' => $desc,
            'why' => $row[2] ?? 'Là một chặng cần có để luồng cho ra kết quả đáng tin.',
            'rule' => $row[3] ?? ($kind === 'llm' ? 'Kết quả AI luôn qua luật chấm trước khi đến tay bạn.' : null),
        ];
    }
}
