<?php

namespace App\Services\Agent\Checkpoints;

use App\Contracts\AiProviderInterface;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Services\Agent\Refresh\AgentRunDispatcher;
use App\Services\Agent\Run\RunPolicy;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Điểm dừng 'outline': phiên của đội AI dừng sau khi lập dàn ý (AgentRunOrchestrator ghi bước 'checkpoint', phiên 'held').
 * Lớp này trả lời "bài nào đang chờ người xem dàn ý" và cho người duyệt dàn ý đã sửa để đội AI viết tiếp.
 * Một bài đang chờ khi: phiên MỚI NHẤT của bài là phiên dừng ở dàn ý và chưa có phiên bản nào được tạo từ lúc phiên đó bắt đầu.
 */
final class OutlineHold
{
    public const MAX_ITEMS = 30;

    public const MAX_ITEM_LENGTH = 300;

    /** @return array{run: AgentRun, outline: list<string>}|null */
    public static function forArticle(Article $article): ?array
    {
        $run = AgentRun::query()->where('article_id', $article->id)->orderByDesc('id')->first();

        return $run ? self::describe($run) : null;
    }

    /** Các phiên đang chờ người xem dàn ý (mới nhất mỗi bài), mới trước. @return Collection<int, AgentRun> */
    public static function pending(): Collection
    {
        return AgentRun::query()->where('status', 'held')
            ->whereIn('id', AgentRun::query()->selectRaw('MAX(id)')->whereNotNull('article_id')->groupBy('article_id'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('agent_run_steps')->whereColumn('agent_run_steps.agent_run_id', 'agent_runs.id')->where('stage', 'checkpoint'))
            ->with('article.website')->orderByDesc('id')->get()
            ->filter(fn (AgentRun $r) => self::describe($r) !== null)->values();
    }

    /** @return array{run: AgentRun, outline: list<string>}|null */
    private static function describe(AgentRun $run): ?array
    {
        if ($run->status !== 'held' || ! $run->article_id) {
            return null;
        }
        $step = $run->steps()->where('stage', 'checkpoint')->orderByDesc('seq')->first();
        if (! $step || ($step->data['checkpoint'] ?? null) !== 'outline') {
            return null;
        }
        $newer = ArticleVersion::query()->where('article_id', $run->article_id)->where('created_at', '>=', $run->started_at ?? $run->created_at)->exists();
        if ($newer) {
            return null;
        }

        return ['run' => $run, 'outline' => self::clean((array) ($step->data['outline'] ?? []))];
    }

    /**
     * Chuẩn hóa dàn ý người nhập: chỉ chuỗi, bỏ mục rỗng, cắt độ dài, tối đa MAX_ITEMS mục.
     *
     * @param  array<int, mixed>  $items
     * @return list<string>
     */
    public static function clean(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $s = trim(preg_replace('/\s+/u', ' ', (string) (is_scalar($i) ? $i : '')));
            if ($s !== '') {
                $out[] = mb_substr($s, 0, self::MAX_ITEM_LENGTH);
            }
        }

        return array_slice($out, 0, self::MAX_ITEMS);
    }

    /**
     * Người duyệt dàn ý đã sửa: giao lại bài với dàn ý đó. Dàn ý có sẵn nên không gọi AI lập dàn ý và không dừng lần nữa.
     *
     * @param  array<int, mixed>  $items
     */
    public function approve(Article $article, array $items, User $by, AiProviderInterface $ai, ?RunPolicy $policy = null): AgentRun
    {
        if (! $by->is_active || ! $by->isWriter()) {
            throw new InvalidArgumentException('Vai trò hiện tại không đủ quyền duyệt dàn ý.');
        }
        if (self::forArticle($article) === null) {
            throw new InvalidArgumentException('Bài này không còn ở trạng thái chờ duyệt dàn ý.');
        }
        $outline = self::clean($items);
        if ($outline === []) {
            throw new InvalidArgumentException('Dàn ý không được để trống: thêm ít nhất một mục.');
        }

        return AgentRunDispatcher::run($article, $ai, $by, 'manual', $article->campaign_id, policy: $policy, presetOutline: $outline, checkpoints: []);
    }
}
