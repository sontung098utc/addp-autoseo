<?php

namespace App\Services\Agent\Roles;

/**
 * FILE MẪU huấn luyện để tải về cho từng vai trò. Hai dạng:
 *  - CSV "bảng tiêu chí": làm đúng theo cột thì được đọc TRỰC TIẾP (không cần AI, không tốn chi phí, kết quả chắc chắn);
 *  - Markdown "văn bản huấn luyện": có sẵn khung các mục; AI đọc, phân loại và chuẩn hóa như mọi tệp tự do.
 * Dòng ví dụ lấy từ rào chắn thật của vai trò để người dùng thấy đúng mức chi tiết cần viết.
 */
final class RoleTrainingTemplate
{
    public const CSV_HEADER = ['Loại', 'Tiêu chí', 'Quy tắc cụ thể', 'Vì sao', 'Mức'];

    /** @return list<list<string>> */
    public static function examples(string $roleKey): array
    {
        $role = (new RoleRegistry)->find($roleKey) ?? [];
        $rows = [];
        foreach (array_slice((array) ($role['guardrails'] ?? []), 0, 2) as $i => $g) {
            $rows[] = ['tiêu chí', 'Ví dụ '.($i + 1).' (xóa dòng này)', (string) $g, 'Lý do ngắn gọn', $i === 0 ? 'Bắt buộc' : 'Nên'];
        }
        $rows[] = ['bài học', '', 'Một quy tắc ngắn người này cần nhớ mọi lần (xóa dòng này)', '', ''];

        return $rows;
    }

    /** CSV UTF-8 có BOM. */
    public static function csv(string $roleKey): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, self::CSV_HEADER);
        foreach (self::examples($roleKey) as $r) {
            fputcsv($fh, $r);
        }
        rewind($fh);

        return "\xEF\xBB\xBF".stream_get_contents($fh);
    }

    public static function markdown(string $roleKey): string
    {
        $role = (new RoleRegistry)->find($roleKey) ?? ['name' => $roleKey, 'goal' => ''];
        $ex = self::examples($roleKey);

        return "<!-- Mẫu huấn luyện cho vai trò «{$role['name']}». Điền theo từng mục rồi tải lên ở Huấn luyện → Nạp từ file. Xóa các dòng ví dụ. -->\n"
            ."# Huấn luyện cho vai trò: {$role['name']}\n\n"
            ."Mục tiêu của vai trò: {$role['goal']}\n\n"
            ."## Tóm tắt tài liệu này dạy gì\n(Một hai câu: tài liệu dành cho vai trò nào, áp dụng cho loại bài nào.)\n\n"
            ."## Tiêu chí (mỗi dòng một tiêu chí cụ thể, kiểm được)\nGhi theo dạng: **Tên tiêu chí** — quy tắc cụ thể — vì sao — mức (Bắt buộc / Nên / Gợi ý).\n\n"
            ."- **Ví dụ: ".mb_substr((string) ($ex[0][2] ?? 'Tên tiêu chí'), 0, 60)."** — ".($ex[0][2] ?? 'Quy tắc cụ thể')." — Lý do ngắn — Bắt buộc\n"
            ."- (thêm tiêu chí của bạn ở đây)\n\n"
            ."## Bài học ngắn (quy tắc cần nhớ mọi lần, mỗi dòng ≤ 300 ký tự)\n- (ví dụ) Luôn nêu tên cơ quan khi trích khuyến cáo.\n\n"
            ."## Quy trình làm việc (nếu có nhiều bước)\n1. Bước một\n2. Bước hai\n\n"
            ."## Kiến thức nền (văn bản tham chiếu nên giữ nguyên)\n(Dán đoạn kiến thức, định nghĩa, thuật ngữ mà vai trò cần biết. Không dán số liệu chưa có nguồn.)\n\n"
            ."## Điều TUYỆT ĐỐI không làm\n- (ví dụ) Không hứa chữa khỏi bệnh.\n";
    }
}
