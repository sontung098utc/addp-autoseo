<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Article\VersionCompareAction;
use App\Filament\Support\Explain\WhyAction;
use App\Filament\Support\Review\ReviewActions;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Filament\Support\Schedule\RescheduleAction;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\KnowledgeGap;
use App\Models\ArticleVersion;
use App\Services\Knowledge\Suggest\KnowledgeSuggestions;
use App\Models\KnowledgeItem;
use App\Models\Brain;
use App\Models\BrandProfile;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KnowledgeItemUsage;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Agent\Checkpoints\OutlineHold;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use App\Services\Agent\Readiness\BrainAutoAttach;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Brain\BrainContext;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Publishing\PublicationHold;
use Filament\Actions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * AG-S27: không gian bài viết. Bấm vào một bài ở màn Bài viết sẽ vào đây. Các tab đi theo đúng chuỗi làm ra một bài:
 * Tổng quan (chuỗi 6 mắt xích + sửa thông tin bài) → Từ khóa và kế hoạch (bài sinh ra từ đâu) → Nghiên cứu và nguồn (AI dựa vào gì để viết)
 * → Nội dung (danh sách phiên bản, bấm Xem sang trang chi tiết phiên bản) → Hoạt động (phiên chạy của đội AI, có hiệu ứng khi đang chạy) → Xuất bản.
 * Chỉ hiện dữ liệu có thật trong hệ thống; mắt xích nào chưa có dữ liệu thì nói rõ là chưa có.
 * Kế thừa EditArticle để dùng lại form và luật khóa trường; mọi thao tác duyệt đi qua ReviewWorkflow như các màn cũ.
 */
class ViewArticle extends EditArticle
{
    protected static string $view = 'filament.article.workspace';

    /** Phiên chạy đang xem ở tab Phiên chạy (mã phiên; trống = phiên mới nhất). Nằm trên địa chỉ (?phien=) để liên kết tới đúng phiên. */
    #[\Livewire\Attributes\Url(as: 'phien')]
    public ?int $phien = null;

    public const ORIGINS = [
        'plan' => 'Kế hoạch nội dung', 'keyword' => 'Kho từ khóa', 'audit' => 'Kiểm tra SEO', 'competitor' => 'Phân tích đối thủ',
        'gsc' => 'Search Console', 'manual' => 'Tạo tay',
    ];

    public const KEYWORD_SOURCES = ['csv' => 'CSV', 'tay' => 'Nhập tay', 'ai' => 'AI đề xuất', 'gsc' => 'Search Console', 'crawl' => 'Trang đã quét', 'competitor' => 'Đối thủ'];

    public const KEYWORD_STATUSES = ['new' => 'Mới', 'planned' => 'Đã lên kế hoạch', 'has_article' => 'Đã có bài'];

    /** Chặng của phiên chạy, theo thứ tự, lấy từ registry loại việc (mã => nhãn), cho dải chặng ở tab Hoạt động. */
    /** @return array<string, string> */
    public static function runStages(): array
    {
        return JobTypeRegistry::stageLabels('article_write');
    }

    public function getBreadcrumb(): string
    {
        return 'Chi tiết';
    }

    /** Lưu thông tin xong ở lại trang bài. */
    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    protected function getFormActions(): array
    {
        return [$this->getSaveFormAction()->label('Lưu thông tin')];
    }

    /** Tiêu đề trang là tên bài (tiêu đề bản mới nhất nếu có, không thì từ khóa chính). */
    public function getTitle(): string
    {
        return (string) ($this->latest()?->title ?: $this->record->primary_keyword);
    }

    /**
     * ArticleRows::lastRun gắn 'lastRun' lên bài như quan hệ giả (setRelation) mỗi khi một nút ở đầu trang tính hiển thị;
     * refresh() sẽ cố nạp lại nó như quan hệ thật và ném RelationNotFoundException (500). Bỏ nó đi trước khi nạp lại.
     */
    private function refreshRecord(): Article
    {
        $this->record->unsetRelation('lastRun');

        return $this->record->refresh();
    }

    private function latest(): ?ArticleVersion
    {
        return $this->record->versions()->orderByDesc('version')->first();
    }

    /** Hộp so sánh; mở từ nút ở tab Nội dung kèm id phiên bản (mặc định bản mới nhất). */
    public function compareAction(): Actions\Action
    {
        return VersionCompareAction::make(
            fn (array $args) => $this->record->versions()->when(isset($args['version']), fn ($q) => $q->whereKey($args['version']))->orderByDesc('version')->first()
        );
    }

    /** Hoàn tác việc hệ thống tự gắn một Trí tuệ cho website của bài; một cú bấm, mọi vai writer trở lên. */
    public function undoAutoAttachAction(): Actions\Action
    {
        return Actions\Action::make('undoAutoAttach')
            ->label('Hoàn tác')
            ->requiresConfirmation()
            ->modalHeading('Gỡ Trí tuệ vừa được tự gắn')
            ->modalDescription('Trí tuệ sẽ không còn dùng cho website này. AI có thể tự gắn lại ở lần giao sau nếu vẫn thấy khớp chủ đề.')
            ->modalSubmitActionLabel('Gỡ')
            ->authorize(fn () => ReviewActions::user()?->isWriter() ?? false)
            ->action(function (array $arguments) {
                $user = ReviewActions::user();
                abort_unless($user?->isWriter(), 403);
                $site = $this->record->website;
                abort_if($site === null, 422);
                $brain = Brain::query()->findOrFail((int) ($arguments['brain'] ?? 0));
                BrainAutoAttach::detach($site, $brain, $user);
                Notification::make()->title('Đã gỡ "'.$brain->name.'" khỏi '.$site->name)->success()->send();
            });
    }

    /** Các Trí tuệ hệ thống đã tự gắn gần đây cho website của bài (rỗng nếu V1 chưa có lớp BrainAutoAttach). @return list<array<string, mixed>> */
    private function recentAutoAttached(): array
    {
        $site = $this->record->website;
        if ($site === null || ! class_exists(BrainAutoAttach::class)) {
            return [];
        }

        return array_values(BrainAutoAttach::recent($site));
    }

    /** Điểm dừng 'outline': người sửa dàn ý rồi duyệt để đội AI viết tiếp (dàn ý có sẵn: không lập lại, không dừng lần nữa). */
    public function approveOutlineAction(): Actions\Action
    {
        return Actions\Action::make('approveOutline')
            ->label('Duyệt dàn ý và viết tiếp')->icon('heroicon-o-check')
            ->visible(fn () => (ReviewActions::user()?->isWriter() ?? false) && OutlineHold::forArticle($this->record) !== null)
            ->modalHeading('Sửa dàn ý rồi cho đội AI viết tiếp')
            ->modalDescription('Sửa chữ, xóa, thêm mục hoặc kéo đổi thứ tự. Đội AI sẽ viết đúng theo dàn ý này.')
            ->modalSubmitActionLabel('Duyệt và viết tiếp')
            ->fillForm(fn () => ['items' => OutlineHold::forArticle($this->record)['outline'] ?? []])
            ->form([
                Repeater::make('items')->label('Dàn ý')->simple(TextInput::make('item')->required()->maxLength(OutlineHold::MAX_ITEM_LENGTH))
                    ->reorderable()->addActionLabel('Thêm mục')->minItems(1)->maxItems(OutlineHold::MAX_ITEMS),
            ])
            ->action(function (array $data) {
                $user = ReviewActions::user();
                abort_unless($user?->isWriter(), 403);
                $ai = AgentAi::forInteractiveUse();
                if ($ai === null) {
                    Notification::make()->title('Chưa kết nối AI')->body('Vào Đội AI → Kết nối AI để nhập khóa trước khi cho viết tiếp.')->warning()->send();

                    return;
                }
                try {
                    $run = (new OutlineHold)->approve($this->record, (array) ($data['items'] ?? []), $user, $ai, AgentAi::policy());
                    Notification::make()->title('Đã duyệt dàn ý · phiên #'.$run->id)->body($run->outcome_reason ?: 'Đội AI đã viết xong bản nháp.')->success()->send();
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title('Chưa duyệt được dàn ý')->body(\App\Support\UserFacingError::from($e))->danger()->send();
                }
                $this->refreshRecord();
            });
    }

    /** Bỏ dàn ý cũ, đội AI lập dàn ý mới (vẫn dừng sau dàn ý nếu chiến dịch đặt điểm dừng). */
    public function regenerateOutlineAction(): Actions\Action
    {
        return Actions\Action::make('regenerateOutline')
            ->label('Giao lại từ đầu')->icon('heroicon-o-arrow-path')->color('gray')
            ->visible(fn () => (ReviewActions::user()?->isWriter() ?? false) && OutlineHold::forArticle($this->record) !== null)
            ->requiresConfirmation()->modalHeading('Giao lại từ đầu')
            ->modalDescription('Bỏ dàn ý này; đội AI lập dàn ý mới (tốn thêm một lượt lập dàn ý).')
            ->action(function () {
                abort_unless(ReviewActions::user()?->isWriter(), 403);
                ArticleResource::assignToAgent($this->record);
                $this->refreshRecord();
            });
    }

    /** Tạm dừng / cho đăng tiếp RIÊNG lượt đăng này (tab Xuất bản); khác với Dừng khẩn cấp toàn hệ thống. */
    public function pausePublicationAction(): Actions\Action
    {
        return Actions\Action::make('pausePublication')->label('Tạm dừng bài này')->color('gray')
            ->requiresConfirmation()->modalHeading('Tạm dừng đăng bài này')
            ->modalDescription('Chỉ lượt đăng của bài này bị dừng; các bài khác vẫn đăng bình thường. Bấm "Tiếp tục đăng" để cho đăng lại.')
            ->action(fn (array $arguments) => $this->holdPublication((int) ($arguments['id'] ?? 0), pause: true));
    }

    public function resumePublicationAction(): Actions\Action
    {
        return Actions\Action::make('resumePublication')->label('Tiếp tục đăng')->color('success')
            ->action(fn (array $arguments) => $this->holdPublication((int) ($arguments['id'] ?? 0), pause: false));
    }

    /** Lượt đăng của CHÍNH bài đang mở (không cho thao tác lượt của bài khác qua tham số). */
    private function publicationFor(array $arguments): Publication
    {
        $p = Publication::query()->whereKey((int) ($arguments['id'] ?? 0))->whereHas('articleVersion', fn ($q) => $q->where('article_id', $this->record->id))->first();
        abort_if($p === null, 404);

        return $p;
    }

    /** Đổi giờ đăng một lượt (người duyệt trở lên); trước đây chỉ có ở trang xem lượt đăng. */
    public function reschedulePublicationAction(): Actions\Action
    {
        return Actions\Action::make('reschedulePublication')->label('Đổi lịch')->icon('heroicon-o-clock')->color('gray')
            ->modalHeading('Đổi giờ đăng')
            ->modalDescription(fn (array $arguments): string => RescheduleAction::description($this->publicationFor($arguments)))
            ->form(fn (array $arguments): array => RescheduleAction::form(fn () => $this->publicationFor($arguments)))
            ->action(function (array $arguments, array $data) {
                abort_unless(ReviewActions::user()?->isApprover(), 403);
                RescheduleAction::run($this->publicationFor($arguments), $data);
                $this->refreshRecord();
            });
    }

    /** Đăng ngay một lượt đăng ĐÃ CÓ của bài này (đã xếp lịch hoặc lỗi). Lượt đang đăng/đã đăng/chờ đối soát không đăng lại được. */
    public function publishNowPublicationAction(): Actions\Action
    {
        return Actions\Action::make('publishNowPublication')->label('Đăng ngay')->icon('heroicon-o-paper-airplane')->color('success')
            ->requiresConfirmation()->modalHeading('Đăng ngay lên website')
            ->modalDescription('Hệ thống gửi bài này lên website đích ngay bây giờ. Bước đăng tra theo khóa xuất bản trước nên không đăng trùng nếu bài đã có.')
            ->action(function (array $arguments) {
                abort_unless(ReviewActions::canApprove(), 403);
                $p = $this->publicationFor($arguments);
                if (! in_array($p->status, ['scheduled', 'failed'], true)) {
                    Notification::make()->title('Không đăng ngay được')->body('Chỉ đăng ngay được lượt đang ở trạng thái "Đã xếp lịch" hoặc "Lỗi".')->danger()->send();

                    return;
                }
                \App\Filament\Support\Review\PublishNow::run($p);
                $this->refreshRecord();
            });
    }

    /** Giải thích vì sao lượt đăng chưa lên (hoặc trạng thái đăng nếu đã đăng). */
    public function whyPublicationAction(): Actions\Action
    {
        return Actions\Action::make('whyPublication')->label('Vì sao chưa đăng?')->icon('heroicon-o-question-mark-circle')->color('gray')
            ->modalHeading('Vì sao bài này chưa được đăng?')
            ->modalContent(fn (array $arguments) => view('filament.explain.why-not-published', ['result' => WhyAction::explain($this->publicationFor($arguments))]))
            ->modalSubmitAction(false)->modalCancelAction(false)->modalCloseButton();
    }

    private function holdPublication(int $id, bool $pause): void
    {
        $user = ReviewActions::user();
        // Chỉ lượt đăng thuộc bài đang mở (không cho đổi lượt của bài khác qua tham số).
        $p = Publication::query()->whereKey($id)->whereHas('articleVersion', fn ($q) => $q->where('article_id', $this->record->id))->first();
        abort_if($p === null, 404);
        try {
            $hold = new PublicationHold;
            $pause ? $hold->pause($user, $p) : $hold->resume($user, $p);
            Notification::make()->title($pause ? 'Đã tạm dừng lượt đăng của bài này' : 'Đã cho bài này đăng tiếp')->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title('Không thực hiện được')->body(\App\Support\UserFacingError::from($e))->danger()->send();
        }
        $this->refreshRecord();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('guidance')->label('Chỉ dẫn cho đội AI')->icon('heroicon-o-chat-bubble-left-ellipsis')->color('gray')
                ->visible(fn () => ArticleResource::canEdit($this->record))
                ->modalHeading('Chỉ dẫn thêm cho đội AI')
                ->modalDescription('Định hướng riêng cho bài này: góc nhìn, đối tượng, điều cần nhấn hoặc tránh. Chỉ dẫn này lưu theo bài và áp cho MỌI lần chạy (giao đội AI, giao làm lại); không thay được quy tắc an toàn, nguồn và từ cấm. Yêu cầu riêng cho một lần làm lại nhập ở hộp "Giao làm lại".')
                ->modalSubmitActionLabel('Lưu chỉ dẫn')
                ->fillForm(fn () => ['guidance' => $this->record->guidance])
                ->form([
                    Textarea::make('guidance')->label('Chỉ dẫn')->rows(6)->maxLength(1500)
                        ->placeholder('Ví dụ: Viết cho người trên 50 tuổi, nhấn mạnh thay đổi ăn uống tại nhà, giọng gần gũi; không nhắc tên thuốc.')
                        ->helperText('Để trống để xóa chỉ dẫn. Sau khi lưu, bấm "Giao đội AI" để áp dụng.'),
                ])
                ->action(function (array $data) {
                    abort_unless(ArticleResource::canEdit($this->record), 403);
                    $g = trim((string) ($data['guidance'] ?? ''));
                    $this->record->update(['guidance' => $g === '' ? null : mb_substr($g, 0, 1500)]);
                    Notification::make()->title($g === '' ? 'Đã xóa chỉ dẫn' : 'Đã lưu chỉ dẫn cho đội AI')->success()->send();
                    $this->refreshRecord();
                }),
            Actions\Action::make('run_agent')->label('Giao đội AI')->icon('heroicon-o-cpu-chip')
                ->visible(fn () => ArticleResource::canAssignToAgent($this->record))
                ->modalHeading('Giao bài này cho đội AI')
                ->modalDescription(fn () => ArticleResource::assignContent($this->record))
                ->modalSubmitActionLabel('Giao việc')
                ->action(function (Actions\Action $action) {
                    $url = ArticleResource::assignToAgent($this->record, background: true);
                    $this->refreshRecord();
                    $url ? $action->redirect($url) : null;
                }),
            Actions\Action::make('redo_agent')->label('Giao làm lại')->icon('heroicon-o-arrow-path')
                ->visible(fn () => ArticleResource::canRedoWithAgent($this->record))
                ->modalHeading('Giao bài này làm lại')
                ->modalDescription(ArticleResource::REDO_DESCRIPTION)
                ->form(fn () => ArticleResource::redoForm($this->record))
                ->modalSubmitActionLabel('Giao làm lại')
                ->action(function (array $data, Actions\Action $action) {
                    $url = ArticleResource::assignToAgent($this->record, background: true, redoMessage: ArticleResource::redoMessage($this->record, $data));
                    $this->refreshRecord();
                    $url ? $action->redirect($url) : null;
                }),
            Actions\Action::make('submit_review')->label('Gửi duyệt')->icon('heroicon-o-paper-airplane')
                ->visible(fn () => ArticleResource::primaryActionFor($this->record) === 'submit_review')
                ->authorize(fn () => ReviewActions::canSubmit())
                ->requiresConfirmation()
                ->action(function () {
                    abort_unless(ReviewActions::canSubmit(), 403);
                    ReviewActions::run(fn () => (new ReviewWorkflow)->submitForReview(ReviewActions::user(), $this->latest()), 'Đã gửi duyệt');
                    $this->refreshRecord();
                }),
            Actions\Action::make('approve')->label('Duyệt')->icon('heroicon-o-check')->color('primary')
                ->visible(fn () => $this->record->workflow_status === 'needs_review' && $this->latest() !== null)
                ->authorize(fn () => ReviewActions::canApprove())
                ->disabled(fn () => \App\Filament\Support\Review\ArticleRows::liveRun($this->record) !== null)
                ->tooltip(fn () => \App\Filament\Support\Review\ArticleRows::liveRun($this->record) ? 'Đội AI đang viết lại bài, chờ xong rồi duyệt bản mới.' : ReviewActions::blockedHint(ReviewActions::qualityFor($this->latest())))
                ->modalHeading(fn () => ReviewActions::qualityFor($this->latest())->hasBlocking() ? 'Duyệt ngoại lệ: bài còn lỗi nặng' : 'Duyệt nội dung')
                ->modalSubmitActionLabel('Duyệt')
                ->form(fn () => ReviewActions::approveForm((int) $this->record->created_by === (int) ReviewActions::user()?->id, ReviewActions::qualityFor($this->latest()), ArticleResource::approveSummary($this->record), $this->latest()))
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    ReviewActions::run(fn () => (new ReviewWorkflow)->approve(ReviewActions::user(), $this->latest(), $data['note'] ?? null, $data['override_reason'] ?? null), 'Đã duyệt nội dung');
                    $this->refreshRecord();
                }),
            Actions\Action::make('reject')->label('Từ chối')->icon('heroicon-o-x-mark')->color('danger')->outlined()
                ->visible(fn () => $this->record->workflow_status === 'needs_review' && $this->latest() !== null)
                ->authorize(fn () => ReviewActions::canApprove())
                ->disabled(fn () => \App\Filament\Support\Review\ArticleRows::liveRun($this->record) !== null)
                ->form(ReviewActions::rejectForm())
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    ReviewActions::run(fn () => (new ReviewWorkflow)->reject(ReviewActions::user(), $this->latest(), $data['reason'] ?? null), 'Đã từ chối bản thảo');
                    $this->refreshRecord();
                }),
            Actions\Action::make('schedule')->label('Xếp lịch')->icon('heroicon-o-clock')->color('primary')
                ->visible(fn () => $this->record->workflow_status === 'approved' && $this->latest() !== null)
                ->authorize(fn () => ReviewActions::canApprove())
                ->form(ReviewActions::scheduleForm())
                ->fillForm(fn () => ['website_id' => $this->record->website_id])
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    ReviewActions::run(function () use ($data) {
                        (new ReviewWorkflow)->schedule(ReviewActions::user(), $this->latest(), ReviewActions::parseVn($data['scheduled_at_vn']), Website::findOrFail($data['website_id']));
                    }, 'Đã xếp lịch xuất bản');
                    $this->refreshRecord();
                }),
            Actions\Action::make('publish_now')->label('Đăng ngay')->icon('heroicon-o-paper-airplane')->color('success')
                ->visible(fn () => $this->record->workflow_status === 'approved' && $this->latest() !== null)
                ->authorize(fn () => ReviewActions::canApprove())
                ->modalHeading('Đăng ngay lên website')
                ->modalDescription('Đăng bản đã duyệt này lên website ngay bây giờ, không hẹn giờ. Bài lên công khai ngay khi thành công.')
                ->form([ReviewActions::websiteSelect()])
                ->fillForm(fn () => ['website_id' => $this->record->website_id])
                ->modalSubmitActionLabel('Đăng ngay')
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    try {
                        $p = (new ReviewWorkflow)->publishNow(ReviewActions::user(), $this->latest(), Website::findOrFail($data['website_id']));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Không đăng được')->body(\App\Support\UserFacingError::from($e))->danger()->send();

                        return;
                    }
                    \App\Filament\Support\Review\PublishNow::run($p);
                    $this->refreshRecord();
                }),
            // Nút Xóa không đứng cạnh Duyệt/Đăng: cất vào menu "Khác", và luôn hỏi xác nhận (xóa bài không hoàn tác được).
            Actions\ActionGroup::make([
                Actions\DeleteAction::make()->visible(fn () => ArticleResource::canDeleteAny())
                    ->requiresConfirmation()
                    ->modalHeading('Xóa bài này?')
                    ->modalDescription('Xóa bài cùng các bản thảo của nó. Không hoàn tác được.')
                    ->modalSubmitActionLabel('Xóa bài'),
            ])->label('Khác')->icon('heroicon-m-ellipsis-horizontal')->color('gray')->button()->outlined(),
        ];
    }

    /**
     * Hoạt động KHO của phiên chạy gần nhất có ghi hoạt động kho: điều phối chọn Trí tuệ nào, đã nạp/học thêm nguồn nào vào kho nào (kèm liên kết nguồn),
     * mục nào tự nhận / chờ duyệt, hay đề xuất lập Trí tuệ mới. Để người dùng biết kho vừa được cập nhật gì và xem lại.
     *
     * @return array{run_id:?int, entries:list<array<string,mixed>>}
     */
    private function kbActivity($runs): array
    {
        foreach ($runs as $run) {
            $entries = [];
            foreach ($run->steps->where('stage', 'research') as $st) {
                $d = (array) $st->data;
                $purpose = (string) ($d['purpose'] ?? '');
                if (! in_array($purpose, ['kb_coordination', 'kb_topup', 'kb_learn', 'web_discover', 'gap'], true)) {
                    continue;
                }
                $entries[] = ['purpose' => $purpose, 'status' => $st->status, 'title' => $st->title, 'detail' => $st->detail, 'brain_id' => $d['brain_id'] ?? null, 'brain_name' => $d['brain_name'] ?? null,
                    'sources' => (array) ($d['sources'] ?? $d['web_sources'] ?? []), 'items_auto' => (int) ($d['items_auto'] ?? 0), 'items_proposed' => (int) ($d['items_proposed'] ?? 0),
                    'item_ids' => (array) ($d['item_ids'] ?? []), 'suggestion' => $d['suggestion'] ?? null];
            }
            if ($entries !== []) {
                return ['run_id' => $run->id, 'entries' => $entries];
            }
        }

        return ['run_id' => null, 'entries' => []];
    }

    /** Mục kho được nhắc trong hoạt động kho (tối đa 40), theo mã. @return \Illuminate\Support\Collection<int, KnowledgeItem> */
    private function kbItemsFor($runs)
    {
        $ids = [];
        foreach ($this->kbActivity($runs)['entries'] as $e) {
            $ids = array_merge($ids, (array) ($e['item_ids']['auto'] ?? []), (array) ($e['item_ids']['proposed'] ?? []));
        }

        return $ids === [] ? collect() : KnowledgeItem::query()->whereIn('id', array_slice(array_unique(array_map('intval', $ids)), 0, 40))->get()->keyBy('id');
    }

    /**
     * Nguồn AI nêu trong bài (sources_cited) kèm liên kết khi khớp được với nguồn có địa chỉ thật (tài liệu web đã đọc, nguồn kho, mục Nguồn tham khảo của bài).
     *
     * @return list<array{name:string, url:?string, matched:?string}>
     */
    private function citedSources(?ArticleVersion $latest, $runs): array
    {
        if (! $latest) {
            return [];
        }
        $candidates = [];
        if (preg_match('#<div class="autoseo-refs">(.*)#s', (string) $latest->html_content, $m) && preg_match_all('#<a\b[^>]*\bhref="([^"]+)"[^>]*>(.*?)</a>#s', $m[1], $links, PREG_SET_ORDER)) {
            foreach ($links as $l) {
                $candidates[] = ['title' => trim(strip_tags(html_entity_decode($l[2]))), 'url' => html_entity_decode($l[1])];
            }
        }
        foreach ($runs->take(3)->flatMap->steps as $st) {
            foreach ((array) (((array) $st->data)['references'] ?? []) as $r) {
                if (is_array($r) && ! empty($r['url'])) {
                    $candidates[] = ['title' => (string) ($r['title'] ?? ''), 'url' => (string) $r['url'], 'domain' => (string) ($r['domain'] ?? '')];
                }
            }
            foreach ((array) (((array) $st->data)['sources'] ?? ((array) $st->data)['web_sources'] ?? []) as $r) {
                if (is_array($r) && ! empty($r['url'])) {
                    $candidates[] = ['title' => (string) ($r['title'] ?? ''), 'url' => (string) $r['url'], 'domain' => (string) ($r['domain'] ?? '')];
                }
            }
        }
        foreach (KnowledgeItemUsage::query()->where('article_version_id', $latest->id)->with('item.source')->get() as $u) {
            if (! empty($u->item?->source?->url)) {
                $candidates[] = ['title' => (string) $u->item->source->title, 'url' => (string) ($u->item->source->final_url ?: $u->item->source->url)];
            }
        }

        return \App\Services\Content\CitedSourceLinks::resolve(\App\Services\Content\Quality\Rules\FactRule::sources($latest->sources_cited), $candidates);
    }

    /**
     * Nút "Tạo Trí tuệ và nạp nguồn đã tìm" ở tab Nghiên cứu và nguồn (gợi ý lập Trí tuệ của điều phối): tạo Trí tuệ theo định hướng của chính bài này, gắn vào website,
     * nạp sẵn các nguồn tin cậy đã tìm được. Cần quyền sửa Kho trí tuệ và gợi ý còn mở.
     */
    public function createBrainFromSuggestion(string $field): void
    {
        $article = $this->refreshRecord();
        $field = trim(mb_substr(strip_tags($field), 0, 160));
        $open = KnowledgeGap::query()->where('website_id', $article->website_id)->where('status', 'open')
            ->where('query', mb_substr(KnowledgeSuggestions::BRAIN_PREFIX.$field, 0, 190))->exists();
        if ($field === '' || ! $open || ! \App\Services\Brain\BrainService::canManage(auth()->user())) {
            Notification::make()->title('Chưa tạo được Trí tuệ')->body($open ? 'Bạn chưa có quyền sửa Kho trí tuệ.' : 'Gợi ý không còn hiệu lực.')->danger()->send();

            return;
        }
        try {
            $r = (new \App\Services\Brain\BrainFromSuggestion)->create($article->website, $field, $article, auth()->user());
        } catch (\Throwable $e) {
            Notification::make()->title('Chưa tạo được Trí tuệ')->body(mb_substr(\App\Support\UserFacingError::from($e), 0, 200))->danger()->send();

            return;
        }
        Notification::make()->title('Đã tạo Trí tuệ «'.$r['brain']->name.'» và gắn vào '.$article->website->name)->body(\App\Services\Brain\BrainFromSuggestion::summary($r))->success()->persistent()->send();
    }

    /** Các tab của không gian bài; tab chưa nạp chỉ hiện khung "Đang tải…" cho tới khi được nạp (xem loadTabs). */
    public const TABS = ['overview', 'keyword', 'research', 'content', 'activity', 'publish'];

    /** @var list<string> tab đã được yêu cầu nạp thêm ngoài tab mở đầu (trạng thái của trang, giữ qua các lượt làm mới) */
    public array $loadedTabs = [];

    /**
     * Nạp dữ liệu và nội dung cho các tab chưa hiện. Lần dựng đầu CHỈ làm tab mở đầu để trang hiện ra nhanh; trình duyệt gọi hàm này ngay sau đó (không tham số = tất cả)
     * và ưu tiên tab người dùng vừa bấm.
     *
     * @param  list<string>  $keys
     */
    public function loadTabs(array $keys = []): void
    {
        $keys = $keys === [] ? self::TABS : array_values(array_intersect($keys, self::TABS));
        $this->loadedTabs = array_values(array_unique(array_merge($this->loadedTabs, $keys)));
    }

    /** Bộ nhớ một-yêu-cầu chỉ bật trong lúc DỰNG trang (sau mọi thao tác ghi): Trí tuệ của website và tài liệu vai trò bị gọi lặp hàng chục lần mỗi lượt dựng. */
    public function dehydrate(): void
    {
        \App\Support\RequestMemo::flush();
    }

    protected function getViewData(): array
    {
        \App\Support\RequestMemo::enable();
        /** @var Article $article */
        $article = $this->refreshRecord()->load('website');
        $versions = $article->versions()->with(['reviewer', 'mediaAssets'])->orderByDesc('version')->get();
        $latest = $versions->first();
        $runs = AgentRun::query()->where('article_id', $article->id)->with('steps')->orderByDesc('id')->get();
        $lastRun = $runs->first();
        // Tab mở đầu theo trạng thái; bài chưa viết luôn mở Tổng quan và không nhớ tab của bài khác.
        $tabDefault = match (true) {
            $latest === null || $article->workflow_status === 'planned' => 'overview',
            $article->workflow_status === 'needs_review' => 'content',
            in_array($article->workflow_status, ['approved', 'scheduled'], true) => 'publish',
            default => 'overview',
        };
        // Nạp tab theo nhu cầu: lần đầu chỉ tab mở đầu (+ tab Phiên chạy nếu địa chỉ có ?phien=); các tab khác nạp sau để trang hiện nhanh. Bộ kiểm thử mặc định nạp hết (TestCase đặt ui.lazy_tabs=false); test riêng cho tính năng này bật lại.
        $lazyTabs = (bool) config('ui.lazy_tabs', true);
        $tabsLoaded = $lazyTabs ? array_values(array_unique(array_merge([$tabDefault], $this->loadedTabs, $this->phien ? ['activity'] : []))) : self::TABS;
        $want = fn (string ...$tabs): bool => array_intersect($tabs, $tabsLoaded) !== [];
        // Chi tiết phiên: phiên đang chọn (?phien=, mặc định mới nhất) cho tab Phiên chạy, và phiên mới nhất cho thẻ Luồng chạy ở Tổng quan; dựng một lần nếu là cùng một phiên.
        $selectedRun = ($this->phien ? $runs->firstWhere('id', $this->phien) : null) ?? $lastRun;
        $canRuns = \App\Filament\Resources\AgentRunResource::canViewAny();
        // Chi tiết phiên nặng (~0,5 s): chỉ dựng cho tab cần nó (Phiên chạy dùng runView, Tổng quan dùng latestRunView).
        $runView = $want('activity') && $selectedRun && $canRuns ? \App\Filament\Support\Agent\RunViewData::for($selectedRun) : null;
        $latestRunView = $want('overview') && $lastRun && $canRuns ? ($selectedRun && $selectedRun->is($lastRun) && $runView ? $runView : \App\Filament\Support\Agent\RunViewData::for($lastRun)) : null;
        $live = $lastRun && RunActivity::isLive($lastRun) ? $lastRun : null;
        $campaignId = $article->campaign_id ?: $lastRun?->campaign_id;
        $publications = Publication::query()->whereIn('article_version_id', $versions->pluck('id'))->with(['website', 'articleVersion'])->orderByDesc('id')->get();

        // Từ khóa: ưu tiên liên kết thật (keyword_id); không có thì dò theo tên trong kho từ khóa của website.
        $keyword = $article->keyword;
        $keywordMatched = false;
        if (! $keyword) {
            $keyword = Keyword::query()->where('website_id', $article->website_id)->where('keyword_key', Keyword::keyFor((string) $article->primary_keyword))->first();
            $keywordMatched = $keyword !== null;
        }
        $clusterId = $article->cluster_id ?: $keyword?->cluster_id;
        $cluster = $clusterId ? KeywordCluster::find($clusterId) : null;
        $clusterKeywords = $cluster && $want('keyword')
            ? Keyword::query()->where('cluster_id', $cluster->id)->with(['articles' => fn ($q) => $q->select('id', 'keyword_id', 'workflow_status')])
                ->orderByRaw('volume is null')->orderByDesc('volume')->limit(10)->get()
            : collect();
        $clusterArticles = $cluster && $want('keyword')
            ? Article::query()->where('cluster_id', $cluster->id)->whereKeyNot($article->id)->orderByDesc('id')->limit(10)->get()
            : collect();

        // Nghiên cứu: lấy từ phiên chạy gần nhất có dàn ý (bước brief) và bước viết/sửa cuối (kiến thức, ảnh, liên kết nội bộ).
        $briefStep = $runs->flatMap->steps->where('stage', 'brief')->sortByDesc('id')->first();
        $writeStep = $runs->flatMap->steps->whereIn('stage', ['write', 'repair'])->sortByDesc('id')->first();
        $researchStep = $runs->flatMap->steps->where('stage', 'research')->sortByDesc('id')->first();

        $unwritten = $latest === null || in_array($article->workflow_status, ['planned', 'drafting', 'rejected'], true);
        // Tính một lần mỗi lượt render; dùng cho khối "Sẵn sàng trước khi giao" và cảnh báo điểm hình thức.
        $readiness = ArticleResource::readiness($article);

        return [
            'readiness' => $readiness,
            'showPreflight' => $unwritten,
            'tabDefault' => $tabDefault,
            'tabsLoaded' => $tabsLoaded,
            'lazyTabs' => $lazyTabs,
            'canUndoAttach' => ReviewActions::user()?->isWriter() ?? false,
            'autoAttached' => $this->recentAutoAttached(),
            'stopInfo' => ($stop = app(EmergencyStopService::class))->isStopped($article->website_id) ? $stop->getStopInfo($article->website_id) : null,
            'needsExpert' => (bool) ($readiness['needs_expert'] ?? false) || ! empty($writeStep?->data['needs_expert_review']),
            'article' => $article,
            'latest' => $latest,
            'versions' => $versions,
            'versionQuality' => ! $want('content') ? [] : $versions->mapWithKeys(fn (ArticleVersion $v) => [$v->id => ReviewActions::qualityFor($v)->counts()])->all(),
            'versionRun' => $runs->whereNotNull('final_version_id')->keyBy('final_version_id'),
            'canOpenVersion' => ArticleVersionResource::canViewAny(),
            'runs' => $runs,
            'live' => $live,
            'liveStage' => $live ? RunActivity::currentStage($live->steps) : null,
            'runFlow' => $this->runFlowOf($runs->first(), $live),
            // Chi tiết đầy đủ MỘT phiên (Diễn biến, Báo cáo, Chi phí và model, Dữ liệu) ngay trong trang bài; phiên đang xem theo ?phien=, mặc định phiên mới nhất.
            'selectedRun' => $selectedRun,
            'runView' => $runView,
            // Phiên MỚI NHẤT cho thẻ Luồng chạy ở Tổng quan: cùng dữ liệu với tab Phiên chạy để bấm từng bước mở ngăn chi tiết như ở đó (cần quyền xem phiên).
            'latestRunView' => $latestRunView,
            'campaign' => $campaignId ? Campaign::find($campaignId) : null,
            'keyword' => $keyword,
            'keywordMatched' => $keywordMatched,
            'cluster' => $cluster,
            'clusterKeywords' => $clusterKeywords,
            'clusterArticles' => $clusterArticles,
            'brains' => BrainContext::forArticle($article, $campaignId)->brains,
            'usages' => $latest ? KnowledgeItemUsage::query()->where('article_version_id', $latest->id)->with('item.source')->get() : collect(),
            'products' => $latest ? KnowledgeItemUsage::query()->where('article_version_id', $latest->id)->whereHas('item', fn ($q) => $q->where('type', 'product'))->with('item.source')->get() : collect(),
            'brand' => BrandProfile::query()->where('website_id', $article->website_id)->first(),
            'outline' => array_values(array_filter((array) ($briefStep?->data['outline'] ?? []), 'is_string')),
            'research' => (array) ($researchStep?->data ?? []) + (array) ($writeStep?->data ?? []),
            'canManageBrains' => \App\Services\Brain\BrainService::canManage(auth()->user()),
            'kbActivity' => $want('research') ? $this->kbActivity($runs) : ['run_id' => null, 'entries' => []],
            'kbItems' => $want('research') ? $this->kbItemsFor($runs) : [],
            'citedSources' => $want('research') ? $this->citedSources($latest, $runs) : [],
            'webSources' => (array) ($runs->flatMap->steps->where('stage', 'research')->filter(fn ($st) => ! empty($st->data['web_sources']))->sortByDesc('id')->first()?->data['web_sources'] ?? []),
            'publications' => $publications,
            'outlineHold' => OutlineHold::forArticle($article),
            'canHold' => ReviewActions::user()?->isApprover() ?? false,
            'canOutline' => ReviewActions::user()?->isWriter() ?? false,
            'quality' => $latest ? ReviewActions::qualityFor($latest) : null,
            'totalCost' => (float) $runs->sum('cost_vnd'),
            'totalTokens' => (int) $runs->sum('tokens_used'),
            'pollSeconds' => RunActivity::POLL_LIVE,
        ];
    }

    /** Luồng chi tiết của phiên gần nhất của bài (đang chạy thì là phiên đang chạy), cùng dữ liệu với trang phiên chạy. Null nếu bài chưa có phiên nào. */
    private function runFlowOf(?AgentRun $run, ?AgentRun $live): ?array
    {
        if (! $run) {
            return null;
        }
        $isLive = $live !== null && $live->is($run);
        $stale = RunActivity::isStale($run);
        $current = ($isLive || $stale) ? RunActivity::currentStageOf($run, $run->steps) : null;

        return [
            'run' => $run,
            'live' => $isLive,
            'stale' => $stale,
            'current' => $isLive ? $current : null,
            'flow' => \App\Filament\Support\Agent\RunFlow::build($run, $run->steps, $isLive, $stale, $current),
            'blueprint' => \App\Filament\Support\Agent\AgentFlowBlueprint::byStage(),
            'elapsed' => RunActivity::formatElapsed(RunActivity::elapsedSeconds($run)),
            'url' => \App\Filament\Resources\AgentRunResource::viewUrl($run),
        ];
    }
}
