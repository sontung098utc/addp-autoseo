<?php

namespace App\Filament\Support\Agent;

use Illuminate\Support\Collection;

/**
 * Sơ đồ luồng của phiên viết bài vẽ như MỘT ĐỒ THỊ (không phải dãy ô nối tiếp): mỗi vai trò một làn, bước đặt theo thứ tự thật,
 * có nhánh song song (Viết ∥ Tạo ảnh) rồi nhập lại ở Lắp ráp, vòng Chấm ↔ Sửa, đường quay lại Kho và nguồn khi điều phối cho tìm thêm tài liệu.
 * Trạng thái từng đường lấy từ dữ liệu thật của phiên: đường chỉ "đã đi" khi cả hai đầu đã chạy; đường quay lại chỉ sáng khi điều phối thật sự đã yêu cầu.
 */
final class FlowGraph
{
    public const NODE_W = 160;

    public const NODE_H = 78;

    private const COLW = 190;

    private const ROWH = 118;

    private const LEFT = 14;

    private const TOP = 46;

    /** Vị trí [cột, hàng] của từng chặng. Hàng 1 là trục viết-chấm; hàng 2 là nhánh ảnh và hoàn tất; "Sửa" đặt cạnh "Chấm" (đẩy ra thêm để vòng lặp có chỗ). */
    private const POS = [
        'guard' => [0, 0], 'risk' => [1, 0], 'sources' => [2, 0], 'synthesize' => [3, 0], 'brief' => [4, 0],
        'image_design' => [0, 1], 'research' => [1, 1], 'write' => [2, 1], 'edit' => [3, 1], 'grade' => [4, 1], 'repair' => [5, 1],
        'images' => [2, 2], 'assemble' => [3, 2], 'learn' => [4, 2], 'handoff' => [5, 2],
    ];

    /** Đường nối [từ, đến, nhãn, loại]. loại: main | fork | join | loop | back. */
    private const EDGES = [
        ['guard', 'risk', null, 'main'], ['risk', 'sources', null, 'main'], ['sources', 'synthesize', null, 'main'], ['synthesize', 'brief', null, 'main'],
        ['brief', 'image_design', null, 'main'], ['image_design', 'research', null, 'main'], ['research', 'write', null, 'main'],
        ['research', 'images', 'song song', 'fork'], ['write', 'edit', null, 'main'], ['edit', 'grade', null, 'main'],
        ['grade', 'repair', 'chưa đạt', 'loop'], ['repair', 'grade', 'chấm lại', 'loop'],
        ['grade', 'assemble', 'đạt', 'main'], ['images', 'assemble', 'nhập lại', 'join'],
        ['grade', 'sources', 'cần thêm nguồn', 'back'],
        ['assemble', 'learn', null, 'main'], ['learn', 'handoff', null, 'main'],
    ];

    /**
     * @param  list<array<string,mixed>>  $nodes  RunFlow::build()['nodes']
     * @param  Collection<int, mixed>  $steps
     * @return array{width:int,height:int,lanes:list<array{label:string,y:int}>,nodes:array<string,array<string,mixed>>,edges:list<array<string,mixed>>}|null
     */
    public static function build(array $nodes, Collection $steps): ?array
    {
        $by = collect($nodes)->keyBy('code');
        foreach (array_keys(self::POS) as $code) {
            if (! $by->has($code)) {
                return null; // loại việc khác (không phải viết bài): dùng lưới cũ
            }
        }
        $pos = self::POS;
        $edgeSpec = self::EDGES;
        if ($by->has('checkpoint')) { // điểm dừng chờ người (không phải phiên nào cũng có): nằm ở làn "Bạn", nối từ Xét rủi ro
            $pos['checkpoint'] = [1, 2];
            $edgeSpec[] = ['risk', 'checkpoint', 'chờ bạn', 'main'];
        }
        $ran = fn (string $c) => (int) ($by[$c]['count'] ?? 0) > 0;
        $moreSearch = $steps->contains(fn ($s) => $s->stage === 'decision' && (bool) (((array) $s->data)['search_more'] ?? false));

        $boxes = [];
        foreach ($pos as $code => [$col, $lane]) {
            $boxes[$code] = ['x' => self::LEFT + $col * self::COLW + ($code === 'repair' ? 30 : 0), 'y' => self::TOP + $lane * self::ROWH, 'node' => $by[$code]];
        }

        $edges = [];
        foreach ($edgeSpec as [$a, $b, $label, $kind]) {
            $both = $ran($a) && $ran($b);
            $state = match (true) {
                $kind === 'back' => $moreSearch ? 'done' : 'idle',
                $kind === 'loop' => $ran('repair') ? 'loop' : 'idle',
                $both && ($by[$b]['state'] ?? '') === 'running' => 'live',
                $both => 'done',
                default => 'idle',
            };
            if ($kind === 'loop' && $label === 'chưa đạt' && $ran('repair')) {
                $label = '↻ '.$by['repair']['count'].' vòng';
            }
            $edges[] = ['from' => $a, 'to' => $b, 'label' => $label, 'kind' => $kind, 'state' => $state] + self::route($boxes, $a, $b, $kind);
        }

        $nodesOut = [];
        foreach ($boxes as $code => $b) {
            $nodesOut[$code] = ['x' => $b['x'], 'y' => $b['y']] + $b['node'];
        }
        return [
            'width' => self::LEFT * 2 + 5 * self::COLW + self::NODE_W + 30,
            'height' => self::TOP + 2 * self::ROWH + self::NODE_H + 22,
            'lanes' => [],
            'nodes' => $nodesOut,
            'edges' => $edges,
        ];
    }

    /** Đường gấp khúc vuông góc giữa hai ô + vị trí đặt nhãn. @return array{d:string,lx:int,ly:int} */
    private static function route(array $boxes, string $a, string $b, string $kind): array
    {
        $w = self::NODE_W;
        $h = self::NODE_H;
        $A = $boxes[$a];
        $B = $boxes[$b];
        $acx = $A['x'] + $w / 2;
        $bcx = $B['x'] + $w / 2;
        $amy = $A['y'] + $h / 2;
        $bmy = $B['y'] + $h / 2;

        if ($kind === 'loop') { // Chấm ↔ Sửa: hai đường ngang song song giữa hai ô, nhãn nằm trên
            $up = $a === 'grade';
            $y = $amy + ($up ? -11 : 11);
            $x1 = $up ? $A['x'] + $w : $A['x'];
            $x2 = $up ? $B['x'] : $B['x'] + $w;

            return ['d' => "M{$x1} {$y}H{$x2}", 'lx' => (int) (($x1 + $x2) / 2), 'ly' => (int) ($up ? $y - 17 : $y + 17)];
        }
        if ($kind === 'back') { // lên khỏi hàng đầu, chạy ngược về Kho và nguồn rồi đi xuống
            $top = self::TOP - 26;

            return ['d' => 'M'.($A['x'] + $w / 2)." {$A['y']}V{$top}H{$bcx}V{$B['y']}", 'lx' => (int) (($acx + $bcx) / 2), 'ly' => $top];
        }
        if (abs($A['y'] - $B['y']) < 1) { // cùng hàng: đi thẳng
            $x1 = $A['x'] + $w;

            return ['d' => "M{$x1} {$amy}H{$B['x']}", 'lx' => (int) (($x1 + $B['x']) / 2), 'ly' => (int) $amy - 14];
        }
        $gapY = $A['y'] + $h + (self::ROWH - $h) / 2;
        if ($B['y'] > $A['y'] && $B['x'] < $A['x'] - 1) { // xuống hàng và quay về đầu hàng (như xuống dòng)
            return ['d' => "M{$acx} ".($A['y'] + $h)."V{$gapY}H{$bcx}V{$B['y']}", 'lx' => (int) (($acx + $bcx) / 2), 'ly' => (int) $gapY];
        }
        if ($kind === 'fork') { // nhánh song song: từ đáy ô nguồn xuống rồi sang mép trái ô đích
            return ['d' => "M{$acx} ".($A['y'] + $h)."V{$bmy}H{$B['x']}", 'lx' => (int) $acx + 36, 'ly' => (int) $bmy - 14];
        }
        if ($B['y'] > $A['y'] && $B['x'] < $A['x'] + $w) { // xuống hàng dưới, lệch trái (Chấm → Lắp ráp)
            return ['d' => "M{$acx} ".($A['y'] + $h)."V{$gapY}H{$bcx}V{$B['y']}", 'lx' => (int) (($acx + $bcx) / 2), 'ly' => (int) $gapY];
        }
        $gx = $A['x'] - 14; // chờ bạn: đi qua khe giữa hai cột

        return ['d' => "M{$acx} ".($A['y'] + $h)."V".($A['y'] + $h + 10)."H".($B['x'] - 14)."V{$bmy}H{$B['x']}", 'lx' => (int) ($B['x'] - 14), 'ly' => (int) (($A['y'] + $h + $bmy) / 2)];
    }
}
