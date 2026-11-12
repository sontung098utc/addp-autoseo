<?php

namespace App\Services\Content\Quality;

final class QualityReport
{
    /** @param list<RuleResult> $results */
    public function __construct(public readonly array $results)
    {
    }

    /** @return list<RuleResult> chưa đạt, P0 trước */
    public function failed(): array
    {
        $failed = array_values(array_filter($this->results, fn (RuleResult $r) => ! $r->passed));
        usort($failed, fn (RuleResult $a, RuleResult $b) => strcmp($a->severity, $b->severity));

        return $failed;
    }

    /** @return list<RuleResult> */
    public function passed(): array
    {
        return array_values(array_filter($this->results, fn (RuleResult $r) => $r->passed));
    }

    public function hasBlocking(): bool
    {
        foreach ($this->results as $r) {
            if ($r->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<RuleResult> */
    public function blocking(): array
    {
        return array_values(array_filter($this->results, fn (RuleResult $r) => $r->isBlocking()));
    }

    /**
     * Các đoạn khẳng định chưa có dấu trích dẫn [KB#id] (luật CITE01, mức P1) của Trí tuệ bắt buộc nguồn. Không chặn bàn giao nhưng ĐƯA VÀO VÒNG SỬA:
     * người viết được yêu cầu thêm trích dẫn từ mục kho đã cung cấp hoặc bỏ khẳng định.
     *
     * @return list<string> đoạn mẫu (đã rút gọn), rỗng nếu không có chỗ nào cần sửa
     */
    public function citationGaps(): array
    {
        foreach ($this->results as $r) {
            if ($r->code === 'CITE01' && ! $r->passed && $r->severity === RuleResult::P1 && $r->details !== []) {
                return $r->details;
            }
        }

        return [];
    }

    /**
     * Chỗ trích dẫn chưa hỗ trợ đúng câu (CITE02, P1): số liệu không có trong mục được trích (tất định) hoặc lượt AI kiểm nghĩa kết luận mục không hỗ trợ câu. Đưa vào vòng sửa.
     *
     * @return list<string>
     */
    public function supportGaps(): array
    {
        $out = [];
        foreach ($this->results as $r) {
            if ($r->code === 'CITE02' && ! $r->passed && $r->details !== []) {
                $out = array_merge($out, $r->details);
            }
        }

        return $out;
    }

    /**
     * Chỗ dùng khối bố cục chưa hợp lý (LAY01, P1): cũng đưa vào vòng sửa, người viết gộp/bỏ khối thừa.
     *
     * @return list<string>
     */
    public function layoutGaps(): array
    {
        foreach ($this->results as $r) {
            if ($r->code === 'LAY01' && ! $r->passed && $r->details !== []) {
                return $r->details;
            }
        }

        return [];
    }

    /** @return list<string> mã luật P0 chưa đạt (duy nhất) */
    public function blockingCodes(): array
    {
        return array_values(array_unique(array_map(fn (RuleResult $r) => $r->code, $this->blocking())));
    }

    /** Số mục CHƯA ĐẠT theo mức. @return array{P0:int,P1:int,P2:int} */
    public function counts(): array
    {
        $c = [RuleResult::P0 => 0, RuleResult::P1 => 0, RuleResult::P2 => 0];
        foreach ($this->failed() as $r) {
            $c[$r->severity]++;
        }

        return $c;
    }
}
