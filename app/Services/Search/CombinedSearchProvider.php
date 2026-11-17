<?php

namespace App\Services\Search;

use App\Contracts\WebSearchProvider;

/**
 * Tìm web theo thứ tự ưu tiên: dịch vụ tìm kiếm (Tavily) trước vì nhanh, rẻ và trả kết quả sạch; CHỈ KHI nó lỗi hoặc hết hạn mức thì mới chuyển
 * sang AI tự tìm (cầu nối Claude/Codex hoặc Gemini). Không chạy cả hai cùng lúc để khỏi tốn thời gian và hạn mức AI. Kết quả rỗng hợp lệ của
 * Tavily (không có trang khớp bộ lọc) KHÔNG kích hoạt dự phòng. Tất cả đều lỗi thì ném lỗi (ưu tiên lỗi hết hạn mức).
 */
final class CombinedSearchProvider implements WebSearchProvider
{
    /** @param  list<WebSearchProvider>  $members  theo thứ tự ưu tiên */
    public function __construct(private array $members) {}

    public function search(string $query, array $options = []): array
    {
        $errors = [];
        foreach ($this->members as $m) {
            try {
                $r = $m->search($query, $options);
                if ($errors !== []) {
                    $r['failed'] = array_map(fn ($e) => $e->getMessage(), $errors); // đã phải dùng dự phòng: ghi lại lý do bên trước lỗi
                }

                return $r;
            } catch (WebSearchUnavailable $e) {
                $errors[] = $e;
            }
        }
        usort($errors, fn ($a, $b) => ($b->getCode() === 429) <=> ($a->getCode() === 429));
        throw $errors[0] ?? new WebSearchUnavailable('Không có nguồn tìm web nào dùng được.');
    }
}
