<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Filament\Support\Review\ReviewActions;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Filament\Support\StatusPresenter;
use App\Models\Article;
use App\Models\Website;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Chiến dịch & Bài viết';

    protected static ?string $modelLabel = 'Bài viết';

    protected static ?string $pluralModelLabel = 'Danh sách Bài viết';

    protected static ?string $navigationGroup = 'Chiến dịch';

    protected static ?int $navigationSort = 2;


    protected static function user(): ?\App\Models\User
    {
        $user = \Illuminate\Support\Facades\Auth::user();

        return $user instanceof \App\Models\User && $user->is_active ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return static::user()?->isWriter() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::user()?->isWriter() ?? false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::user()?->isWriter() ?? false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Thông tin chiến dịch SEO')
                    ->schema([
                        Forms\Components\Select::make('website_id')
                            ->label('Website đích')
                            ->relationship('website', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('primary_keyword')
                            ->label('Từ khóa chính')
                            ->required(),
                        Forms\Components\TagsInput::make('secondary_keywords')
                            ->label('Từ khóa phụ'),
                        Forms\Components\Select::make('search_intent')
                            ->label('Ý định tìm kiếm (Search Intent)')
                            ->options([
                                'informational' => 'Thông tin (Informational)',
                                'commercial' => 'Thương mại (Commercial)',
                                'transactional' => 'Giao dịch (Transactional)',
                                'navigational' => 'Điều hướng (Navigational)',
                            ])
                            ->default('informational')
                            ->required(),
                        Forms\Components\TextInput::make('topic_cluster')
                            ->label('Cụm chủ đề (Topic Cluster)'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('primary_keyword')
                    ->label('Từ khóa chính')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('website.name')->toggleable()
                    ->label('Website')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('topic_cluster')->toggleable()->sortable()
                    ->label('Cụm chủ đề')
                    ->searchable(),
                Tables\Columns\TextColumn::make('search_intent')->toggleable()->sortable()
                    ->label('Ý định'),
                Tables\Columns\TextColumn::make('workflow_status')->sortable()
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => StatusPresenter::label($state, 'article'))
                    ->color(fn (?string $state) => StatusPresenter::color($state, 'article'))
                    ->icon(fn (?string $state) => StatusPresenter::icon($state, 'article')),
                Tables\Columns\TextColumn::make('created_at')->toggleable()
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('workflow_status')->label('Trạng thái')
                    ->options(fn (): array => collect(['planned', 'drafting', 'needs_review', 'rejected', 'approved', 'scheduled', 'published'])
                        ->mapWithKeys(fn (string $v): array => [$v => StatusPresenter::label($v, 'article')])->all()),
                Tables\Filters\SelectFilter::make('website_id')->label('Website')->relationship('website', 'name'),
                Tables\Filters\SelectFilter::make('search_intent')->label('Ý định')->options([
                    'informational' => 'Thông tin', 'commercial' => 'Thương mại', 'transactional' => 'Giao dịch', 'navigational' => 'Điều hướng',
                ]),
                Tables\Filters\Filter::make('needs_review')
                    ->label('Chờ duyệt')
                    ->query(fn ($query) => $query->where('workflow_status', 'needs_review')),
            ])
            ->actions([
                static::submitAction(),
                static::approveAction(),
                static::rejectAction(),
                static::scheduleAction(),
                Tables\Actions\Action::make('generate_ai')
                    ->label('AI Viết bài')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Sinh bản thảo bài viết bằng AI')
                    ->modalDescription('Hệ thống sẽ dựa trên từ khóa chính và cụm chủ đề để sinh dàn ý, bài viết chuẩn SEO và metadata.')
                    ->modalSubmitActionLabel('Bắt đầu sinh')
                    ->action(function (Article $record) {
                        $service = new \App\Services\Content\ContentPipelineService();
                        $version = $service->generateArticleVersion($record);
                        \Filament\Notifications\Notification::make()
                            ->title('Đã sinh xong bản thảo mới!')
                            ->body("Bản thảo phiên bản #{$version->version} ({$version->title}) đã được tạo và chuyển sang chờ duyệt.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Kiểm chất lượng bản mới nhất; null nếu bài chưa có bản nào. */
    private static function qualityOf(Article $record): ?\App\Services\Content\Quality\QualityReport
    {
        $v = $record->versions()->orderByDesc('version')->first();

        return $v ? ReviewActions::qualityFor($v) : null;
    }

    private static function latest(Article $record): \App\Models\ArticleVersion
    {
        $v = $record->versions()->orderByDesc('version')->first();
        if (! $v) {
            throw new \InvalidArgumentException('Bài chưa có bản thảo nào.');
        }

        return $v;
    }

    private static function submitAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('submit_review')->label('Gửi duyệt')->icon('heroicon-o-paper-airplane')
            ->visible(fn (Article $r) => in_array($r->workflow_status, ['planned', 'rejected', 'drafting'], true))
            ->authorize(fn () => ReviewActions::canSubmit())
            ->requiresConfirmation()
            ->action(function (Article $r) {
                abort_unless(ReviewActions::canSubmit(), 403);
                ReviewActions::run(fn () => (new ReviewWorkflow)->submitForReview(ReviewActions::user(), static::latest($r)), 'Đã gửi duyệt');
            });
    }

    private static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')->label('Duyệt')->icon('heroicon-o-check')->color('success')
            ->visible(fn (Article $r) => $r->workflow_status === 'needs_review')
            ->authorize(fn () => ReviewActions::canApprove())
            ->tooltip(fn (Article $r) => ReviewActions::blockedHint(static::qualityOf($r)))
            ->modalHeading(fn (Article $r) => static::qualityOf($r)?->hasBlocking() ? 'Duyệt ngoại lệ: bài còn lỗi P0' : 'Duyệt nội dung')
            ->form(fn (Article $r) => ReviewActions::approveForm((int) $r->created_by === (int) ReviewActions::user()?->id, static::qualityOf($r)))
            ->action(function (Article $r, array $data) {
                abort_unless(ReviewActions::canApprove(), 403);
                ReviewActions::run(fn () => (new ReviewWorkflow)->approve(ReviewActions::user(), static::latest($r), $data['note'] ?? null, $data['override_reason'] ?? null), 'Đã duyệt nội dung');
            });
    }

    private static function rejectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reject')->label('Từ chối')->icon('heroicon-o-x-mark')->color('danger')
            ->visible(fn (Article $r) => $r->workflow_status === 'needs_review')
            ->authorize(fn () => ReviewActions::canApprove())
            ->form(ReviewActions::rejectForm())
            ->action(function (Article $r, array $data) {
                abort_unless(ReviewActions::canApprove(), 403);
                ReviewActions::run(fn () => (new ReviewWorkflow)->reject(ReviewActions::user(), static::latest($r), $data['reason'] ?? null), 'Đã từ chối bản thảo');
            });
    }

    private static function scheduleAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('schedule')->label('Xếp lịch')->icon('heroicon-o-clock')
            ->visible(fn (Article $r) => $r->workflow_status === 'approved')
            ->authorize(fn () => ReviewActions::canApprove())
            ->form(ReviewActions::scheduleForm())
            ->action(function (Article $r, array $data) {
                abort_unless(ReviewActions::canApprove(), 403);
                ReviewActions::run(function () use ($r, $data) {
                    $website = Website::findOrFail($data['website_id']);
                    (new ReviewWorkflow)->schedule(ReviewActions::user(), static::latest($r), ReviewActions::parseVn($data['scheduled_at_vn']), $website);
                }, 'Đã xếp lịch xuất bản');
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticles::route('/'),
            'create' => Pages\CreateArticle::route('/create'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
