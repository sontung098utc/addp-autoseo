<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Filament\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\MediaCollection;
use App\Models\MediaTag;
use App\Models\Website;
use App\Services\Media\MediaUploadBatch;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class ListMediaAssets extends ListRecords
{
    protected static ?string $title = 'Thư viện ảnh';

    protected static string $resource = MediaAssetResource::class;

    protected static string $view = 'filament.media.list-records';

    /** grid = lưới ảnh, list = bảng chi tiết. */
    #[Url(as: 'dang')]
    public string $viewMode = 'grid';

    /** Thư mục đang mở: null = gốc (thư mục + ảnh chưa xếp thư mục), 'all' = tất cả ảnh phẳng, số = id thư mục. */
    #[Url(as: 'thu-muc')]
    public ?string $folder = null;

    /** newest | oldest | name | size */
    #[Url(as: 'xep')]
    public string $sortMode = 'newest';

    protected static bool $toolbarHooksRegistered = false;

    /** Breadcrumb và bộ lọc nhanh nằm cùng một hàng với ô tìm kiếm của bảng (không tách thành thanh riêng). */
    public function boot(): void
    {
        app()->instance('mt.media.page', $this);
        if (! static::$toolbarHooksRegistered) {
            static::$toolbarHooksRegistered = true;
            \Filament\Support\Facades\FilamentView::registerRenderHook(\Filament\Tables\View\TablesRenderHook::TOOLBAR_START, fn () => view('filament.media.toolbar-left'), scopes: static::class);
            \Filament\Support\Facades\FilamentView::registerRenderHook(\Filament\Tables\View\TablesRenderHook::TOOLBAR_SEARCH_BEFORE, fn () => view('filament.media.toolbar-right'), scopes: static::class);
        }
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function openFolder(?string $id): void
    {
        $this->folder = ($id === null || $id === '') ? null : ($id === 'all' ? 'all' : (string) (int) $id);
        $this->tableSearch = '';
        $this->resetPage();
    }

    public function currentFolder(): ?MediaCollection
    {
        return ctype_digit((string) $this->folder) ? MediaCollection::query()->find((int) $this->folder) : null;
    }

    /** @return array<int, array{id:int,name:string}> từ thư mục gốc đến thư mục đang mở */
    public function crumbs(): array
    {
        return $this->currentFolder() ? MediaCollection::pathOf($this->currentFolder()->id) : [];
    }

    /** Thư mục con trực tiếp của vị trí hiện tại (gốc: các thư mục cấp 1). */
    public function visibleFolders()
    {
        $parent = $this->currentFolder()?->id;
        if ($this->folder !== null && $this->folder !== 'all' && $parent === null) {
            return collect(); // id không tồn tại
        }
        $totals = collect(MediaCollection::tree());
        $find = function (array $nodes, int $id) use (&$find) {
            foreach ($nodes as $n) {
                if ($n['id'] === $id) {
                    return $n;
                }
                if ($hit = $find($n['children'], $id)) {
                    return $hit;
                }
            }

            return null;
        };
        $children = $parent === null ? MediaCollection::tree() : ($find(MediaCollection::tree(), $parent)['children'] ?? []);

        return collect($children);
    }

    public function canCreateFolderHere(): bool
    {
        return $this->folder !== 'all' && MediaCollection::depthOf($this->currentFolder()?->id) < MediaCollection::MAX_DEPTH;
    }

    /** Kéo-thả: "asset:ID" hoặc "folder:ID" thả vào thư mục $target (null = gốc). */
    public function dropOn(string $payload, ?int $target): void
    {
        [$type, $id] = array_pad(explode(':', $payload, 2), 2, null);
        if (! ctype_digit((string) $id)) {
            return;
        }
        $type === 'folder' ? $this->moveFolder((int) $id, $target) : $this->moveAsset((int) $id, $target);
    }

    /** Kéo-thả ảnh vào thư mục (hoặc ra gốc khi $folderId = null). */
    public function moveAsset(int $assetId, ?int $folderId): void
    {
        $asset = MediaAsset::query()->find($assetId);
        if ($asset === null || ($folderId !== null && ! MediaCollection::query()->whereKey($folderId)->exists())) {
            return;
        }
        $asset->update(['folder_id' => $folderId]);
        Notification::make()->success()->title($folderId ? 'Đã chuyển ảnh vào thư mục' : 'Đã đưa ảnh ra ngoài thư mục')->send();
    }

    /** Di chuyển thư mục (kèm toàn bộ nội dung). Không cho chuyển vào chính nó/con cháu và không vượt độ sâu tối đa. */
    public function moveFolder(int $folderId, ?int $parentId): void
    {
        $f = MediaCollection::query()->find($folderId);
        if ($f === null || $f->parent_id === $parentId) {
            return;
        }
        if ($parentId !== null && (! MediaCollection::query()->whereKey($parentId)->exists() || in_array($parentId, MediaCollection::withDescendantIds($folderId), true))) {
            Notification::make()->warning()->title('Không thể chuyển thư mục vào chính nó hoặc thư mục con của nó')->send();

            return;
        }
        $subDepth = collect(MediaCollection::withDescendantIds($folderId))->map(fn ($id) => MediaCollection::depthOf($id))->max() - MediaCollection::depthOf($folderId);
        if (MediaCollection::depthOf($parentId) + 1 + $subDepth > MediaCollection::MAX_DEPTH) {
            Notification::make()->warning()->title('Cây thư mục quá sâu (tối đa '.MediaCollection::MAX_DEPTH.' cấp)')->send();

            return;
        }
        $f->update(['parent_id' => $parentId, 'slug' => MediaCollection::uniqueSlug($f->name, $parentId, $f->id)]);
        Notification::make()->success()->title('Đã di chuyển thư mục')->send();
    }

    public function newFolderAction(): Actions\Action
    {
        return Actions\Action::make('newFolder')
            ->modalHeading('Thư mục mới')->modalSubmitActionLabel('Tạo thư mục')
            ->form([Forms\Components\TextInput::make('name')->label('Tên thư mục')->required()->maxLength(120)])
            ->action(function (array $data, array $arguments): void {
                $parent = array_key_exists('parent', $arguments) ? ($arguments['parent'] ?: null) : $this->currentFolder()?->id;
                if ($parent !== null && ! MediaCollection::query()->whereKey($parent)->exists()) {
                    return;
                }
                if (MediaCollection::depthOf($parent) >= MediaCollection::MAX_DEPTH) {
                    Notification::make()->warning()->title('Cây thư mục quá sâu (tối đa '.MediaCollection::MAX_DEPTH.' cấp)')->send();

                    return;
                }
                MediaCollection::create(['name' => $data['name'], 'parent_id' => $parent, 'slug' => MediaCollection::uniqueSlug($data['name'], $parent)]);
                Notification::make()->success()->title('Đã tạo thư mục')->send();
            });
    }

    public function renameFolderAction(): Actions\Action
    {
        return Actions\Action::make('renameFolder')
            ->modalHeading('Đổi tên thư mục')
            ->fillForm(fn (array $arguments) => ['name' => MediaCollection::find($arguments['id'] ?? 0)?->name])
            ->form([Forms\Components\TextInput::make('name')->label('Tên thư mục')->required()->maxLength(120)])
            ->action(function (array $data, array $arguments): void {
                if ($f = MediaCollection::find($arguments['id'] ?? 0)) {
                    $f->update(['name' => $data['name'], 'slug' => MediaCollection::uniqueSlug($data['name'], $f->parent_id, $f->id)]);
                }
            });
    }

    public function moveFolderAction(): Actions\Action
    {
        return Actions\Action::make('moveFolder')
            ->modalHeading('Di chuyển thư mục')->modalSubmitActionLabel('Di chuyển')
            ->fillForm(fn (array $arguments) => ['parent' => MediaCollection::find($arguments['id'] ?? 0)?->parent_id])
            ->form(fn (array $arguments) => [
                Forms\Components\Select::make('parent')->label('Chuyển vào')->searchable()
                    ->options(MediaCollection::treeOptions((int) ($arguments['id'] ?? 0)))->placeholder('Thư viện ảnh (gốc)'),
            ])
            ->action(fn (array $data, array $arguments) => $this->moveFolder((int) ($arguments['id'] ?? 0), $data['parent'] ? (int) $data['parent'] : null));
    }

    public function deleteFolderAction(): Actions\Action
    {
        return Actions\Action::make('deleteFolder')
            ->requiresConfirmation()->color('danger')
            ->modalHeading('Xóa thư mục?')
            ->modalDescription('Thư mục con và ảnh bên trong không bị xóa: chúng được chuyển lên thư mục cha.')
            ->action(function (array $arguments): void {
                $f = MediaCollection::find($arguments['id'] ?? 0);
                if ($f === null) {
                    return;
                }
                // Giữ nguyên nội dung: ảnh và thư mục con lên cấp cha (không mất gì)
                MediaAsset::query()->where('folder_id', $f->id)->update(['folder_id' => $f->parent_id]);
                MediaCollection::query()->where('parent_id', $f->id)->get()->each(
                    fn ($c) => $c->update(['parent_id' => $f->parent_id, 'slug' => MediaCollection::uniqueSlug($c->name, $f->parent_id, $c->id)])
                );
                $wasHere = in_array((int) $this->folder, MediaCollection::withDescendantIds($f->id), true) && ctype_digit((string) $this->folder);
                $f->delete();
                if ($wasHere) {
                    $this->openFolder($f->parent_id ? (string) $f->parent_id : null);
                }
            });
    }

    /** Ảnh đang mở trong khung xem (lightbox) và dữ liệu chỉnh sửa ở bảng bên phải. */
    public ?int $viewing = null;

    /** @var array<string,mixed> */
    public array $edit = [];

    public function openViewer(int $id): void
    {
        $a = MediaAsset::query()->with('tagModels')->find($id);
        if ($a === null) {
            return;
        }
        $this->viewing = $a->id;
        $this->edit = [
            'title' => (string) $a->title,
            'alt_text' => (string) $a->alt_text,
            'caption' => (string) $a->caption,
            'kind' => $a->kind,
            'status' => $a->status,
            'folder_id' => $a->folder_id,
            'website_id' => $a->website_id,
            'is_featured' => (bool) $a->is_featured,
            'tags' => $a->tagModels->pluck('name')->implode(', '),
        ];
    }

    public function closeViewer(): void
    {
        $this->viewing = null;
        $this->edit = [];
    }

    public function viewingAsset(): ?MediaAsset
    {
        return $this->viewing ? MediaAsset::query()->with(['folder', 'website'])->withCount('articleVersions')->find($this->viewing) : null;
    }

    /** Chuyển sang ảnh trước/sau theo đúng danh sách đang lọc và sắp xếp. */
    public function viewerStep(int $dir): void
    {
        $ids = $this->getFilteredSortedTableQuery()->limit(2000)->pluck('media_assets.id')->all();
        $i = array_search($this->viewing, $ids, true);
        if ($i === false || $ids === []) {
            return;
        }
        $this->openViewer((int) $ids[($i + $dir + count($ids)) % count($ids)]);
    }

    public function saveViewer(): void
    {
        $a = $this->viewing ? MediaAsset::find($this->viewing) : null;
        if ($a === null) {
            return;
        }
        $v = $this->validate([
            'edit.title' => ['nullable', 'string', 'max:255'],
            'edit.alt_text' => ['nullable', 'string', 'max:255'],
            'edit.caption' => ['nullable', 'string', 'max:2000'],
            'edit.kind' => ['required', \Illuminate\Validation\Rule::in(MediaAsset::KINDS)],
            'edit.status' => ['required', \Illuminate\Validation\Rule::in(MediaAsset::STATUSES)],
            'edit.folder_id' => ['nullable', 'integer', 'exists:media_collections,id'],
            'edit.website_id' => ['nullable', 'integer', 'exists:websites,id'],
            'edit.is_featured' => ['boolean'],
            'edit.tags' => ['nullable', 'string', 'max:500'],
        ], [], [
            'edit.title' => 'tiêu đề', 'edit.alt_text' => 'thẻ alt', 'edit.caption' => 'chú thích', 'edit.kind' => 'loại ảnh',
        ])['edit'];

        $a->update([
            'title' => $v['title'] ?: null, 'alt_text' => $v['alt_text'] ?: null, 'caption' => $v['caption'] ?: null,
            'kind' => $v['kind'], 'status' => $v['status'], 'folder_id' => $v['folder_id'] ?: null,
            'website_id' => $v['website_id'] ?: null, 'is_featured' => (bool) $v['is_featured'],
        ]);
        $tagIds = collect(explode(',', (string) ($v['tags'] ?? '')))->map(fn ($t) => trim($t))->filter()->unique()->map(
            fn ($t) => MediaTag::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($t) ?: \Illuminate\Support\Str::random(8)], ['name' => $t])->getKey()
        )->all();
        $a->tagModels()->sync($tagIds);
        Notification::make()->success()->title('Đã lưu thay đổi')->duration(2000)->send();
    }

    public function applySuggestion(): void
    {
        if ($a = ($this->viewing ? MediaAsset::find($this->viewing) : null)) {
            if (app(\App\Services\Media\MediaSuggester::class)->apply($a)) {
                $this->openViewer($a->id);
                Notification::make()->success()->title('Đã áp dụng gợi ý')->duration(2000)->send();
            }
        }
    }

    public function dismissSuggestion(): void
    {
        if ($a = ($this->viewing ? MediaAsset::find($this->viewing) : null)) {
            app(\App\Services\Media\MediaSuggester::class)->dismiss($a);
        }
    }

    public function viewerDownload()
    {
        $a = $this->viewing ? MediaAsset::find($this->viewing) : null;

        return $a ? \Illuminate\Support\Facades\Storage::disk($a->disk ?: 'public')->download((string) $a->file_path, (\Illuminate\Support\Str::slug((string) ($a->title ?: $a->file_name)) ?: 'anh').'.webp') : null;
    }

    public function viewerArchive(): void
    {
        if ($a = ($this->viewing ? MediaAsset::find($this->viewing) : null)) {
            $a->update(['status' => 'archived']);
            $this->edit['status'] = 'archived';
            Notification::make()->success()->title('Đã lưu trữ ảnh')->duration(2000)->send();
        }
    }

    /** Xóa bản ghi ảnh (không xóa ảnh đang gắn vào bài đã đăng). */
    public function viewerDelete(): void
    {
        $a = $this->viewing ? MediaAsset::find($this->viewing) : null;
        if ($a === null) {
            return;
        }
        if ($a->isUsedInPublication()) {
            Notification::make()->warning()->title('Ảnh đang gắn vào bài đã đăng nên không xóa được')->body('Hãy dùng "Lưu trữ".')->send();

            return;
        }
        $next = $this->viewerNeighbour($a->id);
        $a->delete();
        $next ? $this->openViewer($next) : $this->closeViewer();
        Notification::make()->success()->title('Đã xóa ảnh')->duration(2000)->send();
    }

    protected function viewerNeighbour(int $id): ?int
    {
        $ids = $this->getFilteredSortedTableQuery()->limit(2000)->pluck('media_assets.id')->all();
        $i = array_search($id, $ids, true);

        return $i === false ? null : ($ids[$i + 1] ?? ($ids[$i - 1] ?? null));
    }

    /** Dòng tóm tắt: số ảnh, dung lượng thật trên ổ (file dùng chung chỉ tính một lần), ảnh chưa dùng, thiếu alt. */
    public function getSubheading(): string|Htmlable|null
    {
        $total = MediaAsset::count();
        if ($total === 0) {
            return 'Chưa có ảnh nào. Bấm "Tải ảnh lên" để bắt đầu.';
        }
        $bytes = (int) MediaAsset::query()->selectRaw('max(file_size) as s')->groupBy('disk', 'file_path')->pluck('s')->sum();
        $unused = MediaAsset::query()->whereDoesntHave('articleVersions')->count();
        $noAlt = MediaAsset::query()->where(fn ($q) => $q->whereNull('alt_text')->orWhere('alt_text', ''))->count();

        return "{$total} ảnh · {$this->formatBytes($bytes)} trên ổ lưu trữ · {$unused} chưa dùng trong bài · {$noAlt} thiếu alt";
    }

    protected function formatBytes(int $b): string
    {
        return $b >= 1048576 ? round($b / 1048576, 1).' MB' : round($b / 1024).' KB';
    }

    public function getTabs(): array
    {
        $counts = MediaAsset::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $tabs = ['all' => Tab::make('Tất cả')->badge((int) $counts->sum())];
        foreach (MediaAsset::STATUS_LABELS as $status => $label) {
            $tabs[$status] = Tab::make($label)->badge((int) ($counts[$status] ?? 0))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleView')
                ->label($this->viewMode === 'grid' ? 'Dạng bảng' : 'Dạng lưới')
                ->icon($this->viewMode === 'grid' ? 'heroicon-o-table-cells' : 'heroicon-o-squares-2x2')
                ->color('gray')
                ->action(function (): void {
                    $this->viewMode = $this->viewMode === 'grid' ? 'list' : 'grid';
                    $this->resetPage();
                }),
            Actions\Action::make('housekeeping')
                ->label('Dọn dẹp')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->modalHeading('Gợi ý dọn dẹp thư viện ảnh')
                ->modalDescription('Chỉ là gợi ý: không có gì bị xóa. Ảnh chưa dùng lâu ngày có thể được chuyển sang "Đã lưu trữ" (vẫn khôi phục được).')
                ->modalContent(fn () => view('filament.media.housekeeping', ['r' => app(\App\Services\Media\MediaHousekeeping::class)->report(90)]))
                ->modalSubmitActionLabel('Lưu trữ ảnh chưa dùng')
                ->modalWidth('3xl')
                ->action(function (): void {
                    $n = app(\App\Services\Media\MediaHousekeeping::class)->archiveUnused(90);
                    Notification::make()->success()->title("Đã lưu trữ {$n} ảnh chưa dùng")->body('Không có ảnh hoặc file nào bị xóa.')->send();
                }),
            Actions\Action::make('upload')
                ->label('Tải ảnh lên')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Tải ảnh lên')
                ->modalDescription(function (array $arguments): string {
                    $id = array_key_exists('folder', $arguments) ? $arguments['folder'] : $this->currentFolder()?->id;
                    $where = $id ? MediaCollection::find($id)?->path_label : null;

                    return 'Tải vào: '.($where ?: 'Thư viện ảnh (chưa xếp thư mục)').'. Có thể đổi thư mục ở bên dưới. Ảnh trùng nội dung với ảnh đã có sẽ không tạo thêm file; ảnh mới vào mục "Chờ phân loại".';
                })
                ->mountUsing(function (\Filament\Forms\Form $form, array $arguments): void {
                    $form->fill([
                        'kind' => 'photo',
                        'folder_id' => array_key_exists('folder', $arguments) ? $arguments['folder'] : $this->currentFolder()?->id,
                    ]);
                })
                ->modalSubmitActionLabel('Tải lên')
                ->modalWidth('2xl')
                ->form([
                    Forms\Components\FileUpload::make('uploads')
                        ->label('Chọn ảnh')
                        ->multiple()->storeFiles(false)->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                        ->maxFiles(30)->maxSize(10240)
                        ->helperText('PNG, JPG, WebP hoặc GIF · tối đa 10 MB mỗi ảnh · tối đa 30 ảnh')
                        ->required()
                        ->validationMessages(['required' => 'Vui lòng chọn ảnh tải lên.']),
                    Forms\Components\Radio::make('kind')
                        ->label('Ảnh này dùng để làm gì?')
                        ->options(MediaAsset::KIND_LABELS)
                        ->inline()->inlineLabel(false)
                        ->required(),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('folder_id')->label('Thư mục')
                            ->options(fn () => MediaCollection::treeOptions())->searchable()->placeholder('Chưa xếp thư mục'),
                        Forms\Components\Select::make('website_id')->label('Gán cho website')
                            ->options(fn () => Website::query()->orderBy('name')->pluck('name', 'id'))->placeholder('Dùng chung cho toàn công ty'),
                    ]),
                ])
                ->action(function (array $data): void {
                    $r = app(MediaUploadBatch::class)->run((array) ($data['uploads'] ?? []), $data);
                    [$title, $body] = MediaUploadBatch::summary($r);
                    Notification::make()->title($title)->body($body)->{$r['failed'] ? 'warning' : 'success'}()->send();
                }),
        ];
    }
}
