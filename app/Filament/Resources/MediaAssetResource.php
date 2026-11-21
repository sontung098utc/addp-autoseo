<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MediaAssetResource\Pages;
use App\Models\MediaAsset;
use App\Models\MediaCollection;
use App\Models\MediaTag;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class MediaAssetResource extends Resource
{
    protected static ?string $model = MediaAsset::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Thư viện ảnh';

    protected static ?string $modelLabel = 'ảnh';

    protected static ?string $pluralModelLabel = 'Thư viện ảnh';

    /** Quy ước giao diện: câu thường ("Chiến dịch tự động"), không để Filament tự Viết Hoa Từng Chữ ở tiêu đề và breadcrumb. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $navigationGroup = 'Chiến dịch';

    protected static ?int $navigationSort = 4;

    /** Không còn trang tạo/sửa riêng: tải lên và chỉnh sửa nằm trong thư viện (hộp tải lên + khung xem bên phải). */
    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        $grid = ($table->getLivewire()->viewMode ?? 'grid') === 'grid';

        $listColumns = [
            Tables\Columns\ImageColumn::make('file_path')
                ->label('Xem trước')
                ->getStateUsing(fn (MediaAsset $r) => $r->thumbnail_url)
                ->size(72)
                ->square()
                ->action(fn (MediaAsset $record, $livewire) => $livewire->openViewer($record->id)),
            Tables\Columns\TextColumn::make('title')->label('Tiêu đề')->searchable()->sortable()
                ->action(fn (MediaAsset $record, $livewire) => $livewire->openViewer($record->id)),
            Tables\Columns\TextColumn::make('kind')->label('Loại')->badge()->formatStateUsing(fn ($state) => MediaAsset::KIND_LABELS[$state] ?? $state)->sortable(),
            Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                ->formatStateUsing(fn ($state) => MediaAsset::STATUS_LABELS[$state] ?? $state)
                ->color(fn ($state) => match ($state) { 'inbox' => 'warning', 'archived' => 'gray', default => 'success' })->sortable(),
            Tables\Columns\TextColumn::make('folder.name')->label('Thư mục')->default('—')->toggleable(),
            Tables\Columns\TextColumn::make('tagModels.name')->label('Thẻ')->badge()->toggleable(),
            Tables\Columns\TextColumn::make('alt_text')->toggleable(isToggledHiddenByDefault: true)->label('Thẻ Alt SEO')->limit(35)->searchable(),
            Tables\Columns\TextColumn::make('website.name')->toggleable()->sortable()->label('Website')->badge()->default('Dùng chung'),
            Tables\Columns\TextColumn::make('article_versions_count')->counts('articleVersions')->label('Đang dùng')->sortable()->toggleable(),
            Tables\Columns\TextColumn::make('mime_type')->toggleable(isToggledHiddenByDefault: true)->sortable()->label('Định dạng')->badge(),
            Tables\Columns\TextColumn::make('file_size')->toggleable(isToggledHiddenByDefault: true)->sortable()->label('Kích thước')
                ->formatStateUsing(fn ($record) => $record?->human_file_size ?? ''),
            Tables\Columns\IconColumn::make('is_featured')->label('Đại diện')->tooltip('Ảnh đại diện nổi bật')->boolean()
                ->trueIcon('heroicon-s-star')->falseIcon('heroicon-o-star')->trueColor('warning')->falseColor('gray')->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('created_at')->toggleable()->label('Ngày tải lên')->dateTime('d/m/Y H:i')->sortable(),
        ];

        $gridColumns = [
            Tables\Columns\Layout\Stack::make([
                Tables\Columns\ViewColumn::make('created_at')
                    ->label('Mới nhất')
                    ->view('filament.media.tile')
                    ->searchable(['title', 'alt_text', 'original_name']),
            ]),
        ];

        $table = $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated($grid ? [24, 48, 96, 100] : [10, 25, 50, 100])
            ->defaultPaginationPageOption($grid ? 24 : 25)
            ->defaultSort(fn (Builder $query) => match ($table->getLivewire()->sortMode ?? 'newest') {
                'oldest' => $query->orderBy('created_at')->orderBy('id'),
                'name' => $query->orderBy('title')->orderBy('id'),
                'size' => $query->orderByDesc('file_size')->orderBy('id'),
                default => $query->orderByDesc('created_at')->orderByDesc('id'),
            })
            ->modifyQueryUsing(function (Builder $query) use ($table) {
                $query->with(['folder', 'website'])->withCount('articleVersions')
                    ->withExists(['articleVersions as in_publication' => fn ($q) => $q->whereHas('publications')]);
                $lw = $table->getLivewire();
                // Kiểu trình quản lý tệp: ở thư mục nào thì thấy ảnh của thư mục đó; đang tìm kiếm hoặc chọn "tất cả ảnh" thì xem toàn bộ.
                $folder = property_exists($lw, 'folder') ? $lw->folder : 'all'; // null = thư mục gốc
                if (filled($lw->tableSearch ?? null) || $folder === 'all') {
                    return $query;
                }

                return ctype_digit((string) $folder) ? $query->where('folder_id', (int) $folder) : $query->whereNull('folder_id');
            })
            ->recordUrl(null)
            ->columns($grid ? $gridColumns : $listColumns);

        if ($grid) {
            $table = $table->contentGrid(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4, '2xl' => 5])->selectable();
        }

        return $table
            ->filters([
                Tables\Filters\SelectFilter::make('scope')->label('Phạm vi')
                    ->options(['global' => 'Dùng chung toàn công ty', 'site' => 'Theo website'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'global' => $query->whereNull('website_id'),
                        'site' => $query->whereNotNull('website_id'),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('website')->relationship('website', 'name')->label('Website'),
                Tables\Filters\SelectFilter::make('kind')->label('Loại ảnh')->options(MediaAsset::KIND_LABELS),
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(MediaAsset::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('folder_id')->label('Thư mục')
                    ->options(fn () => MediaCollection::treeOptions())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('folder_id', MediaCollection::withDescendantIds((int) $data['value'])) : $query),
                Tables\Filters\SelectFilter::make('tag')->label('Thẻ')
                    ->options(fn () => MediaTag::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('tagModels', fn ($t) => $t->whereKey($data['value'])) : $query),
                Tables\Filters\Filter::make('unused')->label('Chưa dùng trong bài')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('articleVersions')),
                Tables\Filters\Filter::make('missing_alt')->label('Thiếu alt')
                    ->query(fn (Builder $query) => $query->where(fn ($w) => $w->whereNull('alt_text')->orWhere('alt_text', ''))),
                Tables\Filters\Filter::make('dup')->label('Trùng nội dung')
                    ->query(fn (Builder $query) => $query->whereIn('sha256', MediaAsset::query()->select('sha256')->groupBy('sha256')->havingRaw('count(*) > 1'))),
                Tables\Filters\TernaryFilter::make('is_featured')->label('Ảnh đại diện'),
            ])
            ->actions(static::rowActions())
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::classifyBulkAction(),
                    Tables\Actions\BulkAction::make('mark_ready')->label('Đánh dấu sẵn sàng')->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'ready']))->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('archive')->label('Lưu trữ')->icon('heroicon-o-archive-box')->requiresConfirmation()
                        ->modalDescription('Ảnh được lưu trữ sẽ không còn tự được chọn cho bài mới. File không bị xóa.')
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'archived']))->deselectRecordsAfterCompletion(),
                    static::safeDeleteBulkAction(),
                ]),
            ]);
    }

    protected static function rowActions(): array
    {
        return [
            Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('open')->label('Xem và chỉnh sửa')->icon('heroicon-o-pencil-square')
                    ->action(fn (MediaAsset $r, $livewire) => $livewire->openViewer($r->id)),
                Tables\Actions\Action::make('download')->label('Tải về')->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (MediaAsset $r) => \Illuminate\Support\Facades\Storage::disk($r->disk ?: 'public')
                        ->download((string) $r->file_path, (Str::slug((string) ($r->title ?: $r->file_name)) ?: 'anh').'.webp')),
                Tables\Actions\Action::make('copy_link')->label('Sao chép link')->icon('heroicon-o-link')
                    ->alpineClickHandler(fn (MediaAsset $r) => 'navigator.clipboard.writeText('.json_encode($r->url).'); $tooltip(\'Đã sao chép link\', { timeout: 1500 })')
                    ->action(fn () => null),
                Tables\Actions\Action::make('rename')->label('Đổi tên')->icon('heroicon-o-pencil')
                    ->fillForm(fn (MediaAsset $r) => ['title' => $r->title])
                    ->form([Forms\Components\TextInput::make('title')->label('Tiêu đề ảnh')->required()->maxLength(255)])
                    ->action(fn (MediaAsset $r, array $data) => $r->update(['title' => $data['title']])),
                Tables\Actions\Action::make('change_kind')->label('Đổi mục đích')->icon('heroicon-o-tag')
                    ->modalHeading('Ảnh này dùng để làm gì?')
                    ->fillForm(fn (MediaAsset $r) => ['kind' => $r->kind])
                    ->form([Forms\Components\Radio::make('kind')->label('')->options(MediaAsset::KIND_LABELS)->required()])
                    ->action(fn (MediaAsset $r, array $data) => $r->update(['kind' => $data['kind']])),
                Tables\Actions\Action::make('archive')->label('Lưu trữ')->icon('heroicon-o-archive-box')
                    ->visible(fn (MediaAsset $r) => $r->status !== 'archived')
                    ->action(fn (MediaAsset $r) => $r->update(['status' => 'archived'])),
                Tables\Actions\DeleteAction::make()->label('Xóa')->hidden(fn (MediaAsset $r) => $r->isUsedInPublication()),
            ])->label('Thao tác')->tooltip('Thao tác'),
        ];
    }

    /** "Phân loại nhanh": gán loại/thư mục/thẻ/trạng thái cho nhiều ảnh một lúc. Để trống = giữ nguyên. */
    public static function classifyBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('classify')
            ->label('Phân loại nhanh')
            ->icon('heroicon-o-tag')
            ->form([
                Forms\Components\Select::make('kind')->label('Loại ảnh')->options(MediaAsset::KIND_LABELS)->placeholder('Giữ nguyên'),
                Forms\Components\Select::make('folder_id')->label('Thư mục')->options(fn () => MediaCollection::treeOptions())->searchable()->placeholder('Giữ nguyên'),
                Forms\Components\Select::make('status')->label('Trạng thái')->options(MediaAsset::STATUS_LABELS)->placeholder('Giữ nguyên'),
                Forms\Components\TagsInput::make('tags')->label('Thêm thẻ')->placeholder('Nhập thẻ rồi Enter'),
            ])
            ->action(function (Collection $records, array $data): void {
                $update = array_filter(['kind' => $data['kind'] ?? null, 'folder_id' => $data['folder_id'] ?? null, 'status' => $data['status'] ?? null], fn ($v) => filled($v));
                $tagIds = collect($data['tags'] ?? [])->map(fn ($t) => trim((string) $t))->filter()->map(fn ($t) => MediaTag::firstOrCreate(
                    ['slug' => Str::slug($t) ?: Str::random(8)], ['name' => $t],
                )->getKey())->all();
                foreach ($records as $r) {
                    if ($update !== []) {
                        $r->update($update);
                    }
                    if ($tagIds !== []) {
                        $r->tagModels()->syncWithoutDetaching($tagIds);
                    }
                }
                Notification::make()->success()->title('Đã phân loại '.$records->count().' ảnh')->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Xóa hàng loạt nhưng bỏ qua ảnh đang gắn vào bài đã đăng (URL đã nằm trên CMS). */
    public static function safeDeleteBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('safe_delete')
            ->label('Xóa')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
            ->modalDescription('Chỉ xóa bản ghi; ảnh đang gắn vào bài đã đăng sẽ được giữ lại. Nên dùng "Lưu trữ" nếu không chắc.')
            ->action(function (Collection $records): void {
                $kept = 0;
                $deleted = 0;
                foreach ($records as $r) {
                    if ($r->isUsedInPublication()) {
                        $kept++;

                        continue;
                    }
                    $r->delete();
                    $deleted++;
                }
                Notification::make()->title("Đã xóa {$deleted} ảnh".($kept ? "; giữ lại {$kept} ảnh đang gắn vào bài đã đăng" : ''))->{$kept ? 'warning' : 'success'}()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMediaAssets::route('/'),
        ];
    }
}
