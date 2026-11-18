<?php

namespace App\Filament\Support\Agent;

use App\Models\AgentRun;
use App\Models\Brain;
use App\Support\RequestMemo;

/**
 * Nhãn dễ hiểu cho phiên "Xây kho trí tuệ" (job_type=brain_build, không gắn bài): xây kho CHO CHỦ ĐỀ NÀO, vì sao chạy, và kết quả thật (có mục hay không).
 * Chủ đề lấy từ input_refs.topic (phiên mới); phiên cũ không có thì suy từ bước "Tìm nguồn" (truy vấn), tên Trí tuệ, hoặc tên miền các nguồn.
 */
final class BuildRunLabel
{
    public const PURPOSE = [
        'gap' => 'tự bổ sung kho khi viết bài',
        'learn' => 'học từ tài liệu bài đã dùng',
        'seed' => 'lập kho từ gợi ý',
        'discover' => 'người dùng chọn nguồn từ tìm web',
        'domain-build' => 'xây kho chuyên ngành',
    ];

    public static function is(AgentRun $run): bool
    {
        return $run->job_type === 'brain_build' && ! $run->article_id;
    }

    /** Chủ đề/lĩnh vực của phiên, hoặc null nếu không suy ra được. */
    public static function topic(AgentRun $run): ?string
    {
        $refs = (array) $run->input_refs;
        $t = trim((string) ($refs['topic'] ?? ''));
        if ($t === '') {
            $q = $run->steps->firstWhere('stage', 'search');
            $t = trim((string) (((array) ($q?->data))['query'] ?? ''));
        }
        if ($t === '' && ! empty($refs['brain_name'])) {
            $t = trim((string) $refs['brain_name']);
        }
        if ($t === '' && ! empty($refs['brain_id'])) {
            $id = (int) $refs['brain_id'];
            $t = trim((string) RequestMemo::remember('brain-name:'.$id, fn () => Brain::query()->whereKey($id)->value('name')));
        }
        if ($t === '') {
            $domains = $run->steps->where('stage', 'source')->map(fn ($s) => (string) (((array) $s->data)['domain'] ?? ''))->filter()->unique()->take(2)->values()->all();
            $t = $domains !== [] ? 'nguồn '.implode(', ', $domains) : '';
        }

        return $t !== '' ? mb_strlen($t) > 90 ? rtrim(mb_substr($t, 0, 90)).'…' : $t : null;
    }

    /** "Xây kho trí tuệ: <chủ đề>" (hoặc "… từ website công ty" khi phiên đọc chính website). */
    public static function title(AgentRun $run): string
    {
        $base = \App\Services\Agent\Pipeline\JobTypeRegistry::find('brain_build')['title'];
        $t = self::topic($run);
        if ($t !== null) {
            return $base.': '.$t;
        }
        $refs = (array) $run->input_refs;

        return isset($refs['purpose']) || isset($refs['mode']) ? $base : $base.': từ website công ty';
    }

    /** Vì sao phiên này chạy (một cụm ngắn) hoặc null. */
    public static function purpose(AgentRun $run): ?string
    {
        $refs = (array) $run->input_refs;

        return self::PURPOSE[(string) ($refs['mode'] ?? '') === 'domain' ? 'domain-build' : (string) ($refs['purpose'] ?? '')] ?? null;
    }

    /**
     * Kết quả THẬT của phiên đã xong: chỉ cho phiên có ghi số mục (output_ref.item_ids). Không có mục nào thì không được ghi "Đã có bản nháp chờ duyệt".
     *
     * @return ?array{0:string,1:string} [nhãn, màu] hoặc null để dùng nhãn trạng thái chung
     */
    public static function outcome(AgentRun $run): ?array
    {
        if ($run->job_type !== 'brain_build' || $run->status !== 'ready_for_review') {
            return null;
        }
        $out = (array) $run->output_ref;
        if (! array_key_exists('item_ids', $out)) {
            return null;
        }
        $n = count((array) $out['item_ids']);
        if ($n === 0) {
            return ['Xong nhưng không có mục mới', 'warning'];
        }

        return ['Đã nạp '.$n.' mục: '.(int) ($out['auto_approved'] ?? 0).' tự nhận, '.(int) ($out['proposed'] ?? 0).' chờ duyệt', 'success'];
    }
}
