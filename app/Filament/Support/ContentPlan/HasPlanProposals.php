<?php

namespace App\Filament\Support\ContentPlan;

use App\Models\Campaign;
use App\Models\Website;
use App\Services\Planning\ContentPlanner;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Hộp "Đề xuất kế hoạch (theo luật)" dùng chung cho trang Kế hoạch (cấp cụm) và tab Kế hoạch trong chiến dịch.
 * Bài thêm vào kế hoạch luôn được GÁN chiến dịch: cố định theo trang chiến dịch, hoặc theo ô chọn ("Bài lẻ" = không chiến dịch).
 */
trait HasPlanProposals
{
    /** @var list<int> keyword_id đã chọn trong hộp đề xuất */
    public array $planPick = [];

    /** Nơi nhận bài khi trang không cố định chiến dịch: '' (chưa chọn) | 'solo' (bài lẻ) | id chiến dịch. */
    public ?string $planTarget = null;

    abstract protected function planSite(): ?Website;

    /** Nơi nhận cố định của trang này: 'solo' | id chiến dịch; null = người dùng chọn ở ô "Gán vào". */
    abstract protected function planFixedTarget(): ?string;

    public function addProposed(): void
    {
        abort_unless(ContentPlanner::canPlan(Auth::user()), 403);
        $site = $this->planSite();
        if (! $site || $this->planPick === []) {
            return;
        }
        $t = (string) ($this->planFixedTarget() ?? $this->planTarget);
        if ($t === '' || ($t !== 'solo' && ! ctype_digit($t))) {
            Notification::make()->title('Chọn nơi nhận bài')->body('Chọn một chiến dịch hoặc "Bài lẻ" trước khi thêm vào kế hoạch.')->warning()->send();

            return;
        }
        $campaignId = $t === 'solo' ? null : (int) $t;
        try {
            $r = (new ContentPlanner)->create(Auth::user(), $site, array_map('intval', $this->planPick), $campaignId);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Chưa thêm vào kế hoạch')->body($e->getMessage())->danger()->send();

            return;
        }
        $where = $campaignId ? 'chiến dịch "'.Campaign::query()->whereKey($campaignId)->value('name').'"' : 'Bài lẻ';
        $n = Notification::make()->title("Đã thêm {$r['created']} bài vào kế hoạch ($where)")
            ->body($r['skipped'] ? 'Bỏ qua: '.implode('; ', $r['skipped']) : 'Bài ở trạng thái Đã lên kế hoạch; chiến dịch sẽ lấy bài theo thứ tự ưu tiên.');
        $r['skipped'] ? $n->warning() : $n->success();
        $n->send();
        $this->planPick = [];
    }
}
