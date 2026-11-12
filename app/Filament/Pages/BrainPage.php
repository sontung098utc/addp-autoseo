<?php

namespace App\Filament\Pages;

use App\Filament\Support\BrandProfileEditor;
use App\Models\Brain;
use App\Models\BrainProfile;
use App\Models\BrainSource;
use App\Models\BrandProfile;
use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainService;
use App\Services\Brain\BrainSourceRegistry;
use App\Services\Brand\BrandProfileData;
use App\Services\Knowledge\KnowledgeError;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * AG-B1: trang MỘT Trí tuệ. Tab: Hồ sơ lĩnh vực · (Hộp duyệt, Hiểu biết, Nguồn, Cảnh báo, Khoảng trống: TÁI DÙNG màn kho kiến thức, lọc theo brain_id) · Cài đặt.
 * Quyền TẠM THỜI: admin + approver sửa; biên tập viên chỉ xem (quyền duyệt tính sau theo yêu cầu người dùng).
 * Nội dung hồ sơ là dữ liệu do người dùng nhập: luôn hiển thị qua escape, vào prompt trong khung dữ liệu.
 */
class BrainPage extends KnowledgePage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'tri-tue/{brain}';

    protected static string $view = 'filament.pages.knowledge';

    protected static ?string $title = 'Trí tuệ';

    /** @var array<string,mixed> */
    public ?array $profileData = [];

    /** @var array{ai_reliance:string,risk_class:string} */
    public array $settings = ['ai_reliance' => 'numbers', 'risk_class' => 'low'];

    /** @var array<int,array{on:bool,priority:int}> website_id => liên kết */
    public array $siteLinks = [];

    /** AG-B2: hồ sơ doanh nghiệp (brand_profiles) của website được chọn, chỉ dùng cho Trí tuệ Thương hiệu. */
    public ?int $brandWebsiteId = null;

    /** AG-B3: form "Thêm nguồn" của Căn cứ chuẩn mực. */
    public string $srcName = '';

    public string $srcDomain = '';

    public string $srcTier = '';

    public string $srcNote = '';

    /** @var array<string,mixed> */
    public array $brandData = [];

    protected function getForms(): array
    {
        return ['form', 'brandForm'];
    }

    public function mount(int|string $brain = 0): void
    {
        $b = Brain::query()->with(['profile', 'websites'])->findOrFail((int) $brain);
        $this->brainId = $b->id;
        $this->loadBrain($b);
    }

    private function brain(): Brain
    {
        return Brain::query()->with(['profile', 'websites'])->findOrFail((int) $this->brainId);
    }

    public function getTitle(): string
    {
        return $this->brainId ? (string) Brain::query()->whereKey($this->brainId)->value('name') : 'Trí tuệ';
    }

    private function loadBrain(Brain $b): void
    {
        $this->settings = ['ai_reliance' => $b->reliance(), 'risk_class' => $b->riskClass()];
        $linked = $b->websites->mapWithKeys(fn ($w) => [$w->id => (int) $w->pivot->priority]);
        $this->siteLinks = Website::query()->orderBy('name')->pluck('id')->mapWithKeys(fn ($id) => [$id => ['on' => $linked->has($id), 'priority' => (int) ($linked[$id] ?? 10)]])->all();
        $this->form->fill($this->profileToForm($b->profile));
        $ids = $b->websites->pluck('id')->all();
        if ($this->isBrandBrain($b) && ! in_array($this->brandWebsiteId, $ids, true)) {
            $this->brandWebsiteId = $ids[0] ?? null;
        }
        $this->loadBrand();
    }

    private function isBrandBrain(Brain $b): bool
    {
        return $b->template_key === 'thuong_hieu';
    }

    private function loadBrand(): void
    {
        $this->brandForm->fill(BrandProfileEditor::formState($this->brandWebsiteId));
    }

    public function updatedBrandWebsiteId(): void
    {
        // Chỉ cho chọn website đã gắn với Trí tuệ này.
        if (! $this->brain()->websites->contains('id', (int) $this->brandWebsiteId)) {
            $this->brandWebsiteId = $this->brain()->websites->first()?->id;
        }
        $this->loadBrand();
    }

    public function brandForm(Form $form): Form
    {
        return $form->disabled(fn () => ! BrandProfileEditor::canEdit(Auth::user()))->statePath('brandData')->schema(BrandProfileEditor::schema());
    }

    /** Lưu vào CHÍNH bảng brand_profiles của website (mỗi website một bản). Cùng kiểm tra và quyền như trang Hồ sơ doanh nghiệp cũ. */
    public function saveBrand(): void
    {
        abort_unless(BrandProfileEditor::canEdit(Auth::user()), 403);
        $b = $this->brain();
        abort_unless($this->isBrandBrain($b), 404);
        $site = $b->websites->firstWhere('id', (int) $this->brandWebsiteId);
        if (! $site) {
            throw ValidationException::withMessages(['brandWebsiteId' => 'Hãy gắn website cho Trí tuệ này (tab Cài đặt) rồi chọn website.']);
        }
        BrandProfileEditor::save($site, $this->brandData, Auth::user());
        $this->loadBrand();
        Notification::make()->title('Đã lưu hồ sơ doanh nghiệp')->success()->send();
    }

    /** @return array<string,mixed> */
    private function profileToForm(?BrainProfile $p): array
    {
        $v = (array) ($p?->voice ?? []);

        return [
            'voice' => ['tone' => $v['tone'] ?? '', 'formality' => $v['formality'] ?? '', 'pronouns' => $v['pronouns'] ?? '', 'do' => array_values((array) ($v['do'] ?? [])), 'dont' => array_values((array) ($v['dont'] ?? []))],
            'article_types' => array_values(array_map(fn ($t) => ['key' => $t['key'] ?? '', 'name' => $t['name'] ?? '', 'outline' => array_values((array) ($t['outline'] ?? []))], (array) (($p?->structure ?? [])['article_types'] ?? []))),
            'preferred' => array_values(array_map(fn ($t) => ['term' => $t['term'] ?? '', 'definition' => $t['definition'] ?? '', 'avoid' => array_values((array) ($t['avoid'] ?? []))], (array) (($p?->terminology ?? [])['preferred'] ?? []))),
            'abbreviations' => array_values((array) (($p?->terminology ?? [])['abbreviations'] ?? [])),
            'rules' => implode("\n", (array) (($p?->reasoning ?? [])['rules'] ?? [])),
            'exemplars' => array_values((array) ($p?->exemplars ?? [])),
            'rubric_notes' => (string) ($p?->rubric_notes ?? ''),
        ];
    }

    public function form(Form $form): Form
    {
        $tags = fn (string $name, string $label, string $hint) => Forms\Components\TagsInput::make($name)->label($label)->hintIcon('heroicon-o-information-circle', $hint);

        return $form->statePath('profileData')->disabled(fn () => ! static::canManage())->schema([
            Forms\Components\Section::make('Giọng và văn phong')->collapsible()->schema([
                Forms\Components\TextInput::make('voice.tone')->label('Tông giọng')->maxLength(200)->hintIcon('heroicon-o-information-circle', 'Ví dụ: ấm áp, điềm tĩnh, gần gũi. Bạn tự điền theo lĩnh vực.'),
                Forms\Components\TextInput::make('voice.formality')->label('Mức trang trọng')->maxLength(120),
                Forms\Components\TextInput::make('voice.pronouns')->label('Cách xưng hô')->maxLength(200),
                $tags('voice.do', 'Nên', 'Mỗi ý một thẻ, nhấn Enter để thêm. AI được nhắc bám các ý này.'),
                $tags('voice.dont', 'Tránh', 'Những điều AI không nên viết trong lĩnh vực này.'),
            ])->columns(2),
            Forms\Components\Section::make('Cấu trúc bài')->collapsible()->collapsed()->schema([
                Forms\Components\Repeater::make('article_types')->label('Khung theo loại bài')->maxItems(12)->addActionLabel('Thêm loại bài')->collapsible()->itemLabel(fn (array $state) => $state['name'] ?? null)
                    ->hintIcon('heroicon-o-information-circle', 'Mỗi loại bài (giải thích, so sánh, hướng dẫn…) có khung đề mục riêng. Đây là KHUNG, AI chọn khung hợp với từ khóa của bài.')
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('Tên loại bài')->required()->maxLength(120),
                        Forms\Components\TagsInput::make('outline')->label('Các đề mục theo thứ tự')->hintIcon('heroicon-o-information-circle', 'Nhấn Enter sau mỗi đề mục; thứ tự giữ nguyên.'),
                    ]),
            ]),
            Forms\Components\Section::make('Thuật ngữ')->collapsible()->collapsed()->schema([
                Forms\Components\Repeater::make('preferred')->label('Thuật ngữ ưu tiên')->maxItems(60)->addActionLabel('Thêm thuật ngữ')->collapsible()->itemLabel(fn (array $state) => $state['term'] ?? null)
                    ->hintIcon('heroicon-o-information-circle', 'Hệ thống không tự điền thuật ngữ. Chuyên gia của bạn điền từ nên dùng, nghĩa và từ nên tránh.')
                    ->schema([
                        Forms\Components\TextInput::make('term')->label('Thuật ngữ')->required()->maxLength(120),
                        Forms\Components\TextInput::make('definition')->label('Giải nghĩa ngắn')->maxLength(300),
                        Forms\Components\TagsInput::make('avoid')->label('Cách nói nên tránh'),
                    ])->columns(3),
                $tags('abbreviations', 'Viết tắt', 'Ví dụ: "ABC = giải nghĩa". Mỗi viết tắt một thẻ.'),
            ]),
            Forms\Components\Section::make('Logic lập luận')->collapsible()->collapsed()->schema([
                Forms\Components\Textarea::make('rules')->label('Quy tắc lập luận')->rows(5)->hintIcon('heroicon-o-information-circle', 'Mỗi dòng một quy tắc: nêu bằng chứng thế nào, miễn trừ trách nhiệm, khi nào khuyến nghị gặp chuyên gia…'),
            ]),
            Forms\Components\Section::make('Bài mẫu')->collapsible()->collapsed()->schema([
                Forms\Components\Repeater::make('exemplars')->label('Bài mẫu')->maxItems(BrainProfile::MAX_EXEMPLARS)->addActionLabel('Thêm bài mẫu')->collapsible()->itemLabel(fn (array $state) => $state['title'] ?? null)
                    ->hintIcon('heroicon-o-information-circle', 'Tối đa 5 bài, cùng một giọng. AI chỉ học giọng từ tối đa 2 bài (cắt ngắn), không chép sự kiện.')
                    ->schema([
                        Forms\Components\TextInput::make('title')->label('Tiêu đề')->maxLength(160),
                        Forms\Components\Textarea::make('text_or_url')->label('Nội dung hoặc đường dẫn')->rows(4)->maxLength(6000),
                        Forms\Components\TextInput::make('note')->label('Ghi chú vì sao là bài mẫu')->maxLength(300),
                    ]),
            ]),
            Forms\Components\Section::make('Rubric riêng')->collapsible()->collapsed()->schema([
                Forms\Components\Textarea::make('rubric_notes')->label('Tiêu chí chấm bổ sung của lĩnh vực')->rows(4)->maxLength(2000)
                    ->hintIcon('heroicon-o-information-circle', 'Ghi nhận để dùng cho bộ chấm ở các bước sau; bản này chưa chấm tự động theo ghi chú này.'),
            ]),
        ]);
    }

    public function saveProfile(): void
    {
        $this->guard();
        $d = $this->form->getState();
        $data = [
            'voice' => $d['voice'] ?? [],
            'structure' => ['article_types' => array_values((array) ($d['article_types'] ?? []))],
            'terminology' => ['preferred' => array_values((array) ($d['preferred'] ?? [])), 'abbreviations' => $d['abbreviations'] ?? []],
            'reasoning' => ['rules' => preg_split('/\R/u', (string) ($d['rules'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []],
            'rubric_notes' => $d['rubric_notes'] ?? '',
            'exemplars' => array_values((array) ($d['exemplars'] ?? [])),
        ];
        try {
            $p = (new BrainService)->saveProfile($this->brain(), $data, Auth::user());
            $this->form->fill($this->profileToForm($p));
            Notification::make()->title('Đã lưu Hồ sơ lĩnh vực')->success()->send();
        } catch (KnowledgeError|\InvalidArgumentException $e) {
            Notification::make()->title('Chưa lưu được')->body($e->getMessage())->danger()->send();
        }
    }

    public function saveSettings(): void
    {
        $this->guard();
        try {
            $svc = new BrainService;
            $b = $this->brain();
            $svc->saveSettings($b, (string) ($this->settings['ai_reliance'] ?? ''), (string) ($this->settings['risk_class'] ?? ''), Auth::user());
            $svc->linkWebsites($b, collect($this->siteLinks)->filter(fn ($l) => ! empty($l['on']))->map(fn ($l) => (int) ($l['priority'] ?? 10))->all(), Auth::user());
            Notification::make()->title('Đã lưu cài đặt')->success()->send();
        } catch (KnowledgeError|\InvalidArgumentException $e) {
            Notification::make()->title('Chưa lưu được')->body($e->getMessage())->danger()->send();
        }
    }

    // ---- AG-B3: Căn cứ chuẩn mực ----

    public function addSource(): void
    {
        $this->guard();
        try {
            (new BrainSourceRegistry)->add($this->brain(), $this->srcName, $this->srcDomain, $this->srcTier ?: null, $this->srcNote, Auth::user());
            $this->reset(['srcName', 'srcDomain', 'srcTier', 'srcNote']);
            Notification::make()->title('Đã thêm vào Căn cứ chuẩn mực')->success()->send();
        } catch (KnowledgeError $e) {
            Notification::make()->title('Chưa thêm được')->body($e->getMessage())->danger()->send();
        }
    }

    private function ownSource(int $id): BrainSource
    {
        return BrainSource::query()->where('brain_id', $this->brain()->id)->findOrFail($id);
    }

    public function setSourceTier(int $id, string $tier): void
    {
        $this->guard();
        try {
            $s = $this->ownSource($id);
            if ($tier === '') {
                (new BrainSourceRegistry)->unconfirm($s, Auth::user());
                Notification::make()->title('Đã bỏ bậc của "'.$s->name.'"')->body('Nguồn trở về chưa xác nhận, không còn được tính.')->success()->send();

                return;
            }
            $s = (new BrainSourceRegistry)->confirm($s, $tier, Auth::user());
            Notification::make()->title($tier === 'blocked' ? 'Đã chặn "'.$s->name.'"' : 'Đã xác nhận "'.$s->name.'" ở '.mb_strtolower(BrainSource::TIERS[$tier] ?? $tier))->success()->send();
        } catch (KnowledgeError $e) {
            Notification::make()->title('Chưa lưu được')->body($e->getMessage())->danger()->send();
        }
    }

    public function unconfirmSource(int $id): void
    {
        $this->guard();
        $s = (new BrainSourceRegistry)->unconfirm($this->ownSource($id), Auth::user());
        Notification::make()->title('Đã rút xác nhận "'.$s->name.'"')->body('Nguồn không còn được tính khi kiểm bằng chứng.')->success()->send();
    }

    public function removeSource(int $id): void
    {
        $this->guard();
        $s = $this->ownSource($id);
        (new BrainSourceRegistry)->remove($s, Auth::user());
        Notification::make()->title('Đã xóa "'.$s->name.'" khỏi Căn cứ chuẩn mực')->success()->send();
    }

    /**
     * Xây kho chuyên ngành: hệ thống tự chạy quy trình (AI làm theo kỹ năng, chuyên gia duyệt) bằng tiến trình nền; kết quả ở trang Phiên chạy và báo cáo trên Trí tuệ.
     * Không chạy được nền (CSDL không dùng chung được giữa hai tiến trình) thì chỉ đường dẫn lệnh, không chạy trong yêu cầu web.
     */
    public function buildDomain(bool $review = false): void
    {
        abort_unless(static::canManage(), 403);
        $b = $this->brain();
        $by = Auth::user();
        $av = (new \App\Services\Knowledge\KnowledgeAiIngest)->availability();
        if (! $av['ok']) {
            Notification::make()->title('Chưa kết nối AI')->body((string) $av['reason'])->warning()->send();

            return;
        }
        if (! $b->websites()->exists()) {
            Notification::make()->title('Trí tuệ chưa gắn website')->body('Gắn một website ở Cài đặt rồi xây kho (nguồn và mục kiến thức thuộc về website).')->warning()->send();

            return;
        }
        $cmd = ['brain:build-domain', (string) $b->id, '--user='.$by->id] + ($review ? [3 => '--review'] : []);
        if (! \App\Services\Images\ImageJob::dbShareable()) {
            Notification::make()->title('Chạy bằng lệnh')->body('Môi trường này không chạy nền được. Dùng: php artisan '.implode(' ', $cmd))->warning()->send();

            return;
        }
        try {
            $php = (new \Symfony\Component\Process\PhpExecutableFinder)->find(false) ?: PHP_BINARY;
            \Illuminate\Support\Facades\Process::path(base_path())->timeout(7200)->start(array_merge([$php, 'artisan'], $cmd));
        } catch (\Throwable) {
            Notification::make()->title('Không khởi động được tiến trình nền')->body('Dùng lệnh: php artisan '.implode(' ', $cmd))->danger()->send();

            return;
        }
        Notification::make()->title($review ? 'Đã bắt đầu rà soát kho' : 'Đã bắt đầu xây kho chuyên ngành')->body('AI làm theo kỹ năng của từng vai trò; xem tiến độ ở Đội AI → Phiên chạy (loại "Xây kho trí tuệ"). Mọi mục rủi ro cao đều chờ chuyên gia duyệt.')->success()->send();
    }

    public function toggleArchive(): void
    {
        $this->guard();
        $b = $this->brain();
        (new BrainService)->setArchived($b, $b->isActive(), Auth::user());
        Notification::make()->title($b->isActive() ? 'Đã lưu trữ Trí tuệ (AI ngừng dùng)' : 'Đã khôi phục Trí tuệ')->success()->send();
    }

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        $b = $this->brain();
        $isBrand = $this->isBrandBrain($b);
        $bp = $isBrand && $this->brandWebsiteId ? BrandProfile::query()->where('website_id', $this->brandWebsiteId)->first() : null;
        $bc = BrandProfileData::completeness($bp);

        return array_merge(parent::getViewData(), [
            'brain' => $b,
            'isBrand' => $isBrand, 'brandSites' => $b->websites, 'brandExists' => (bool) $bp,
            'brandCompleteness' => $bc, 'canEditBrand' => BrandProfileEditor::canEdit(Auth::user()),
            'authoritySources' => $b->authoritySources()->orderByRaw("case when status='confirmed' then 0 else 1 end")->orderBy('name')->get(),
            'tiers' => BrainSource::TIERS,
            'confirmers' => User::query()->whereIn('id', $b->authoritySources()->whereNotNull('confirmed_by')->pluck('confirmed_by'))->pluck('name', 'id')->all(),
            'sampleLabel' => (string) ($b->settings['sample_label'] ?? ''),
            'completeness' => ($b->profile ?? new BrainProfile)->completeness(),
            'missingFields' => collect(BrainProfile::FIELDS)->filter(fn ($l, $path) => ! ($b->profile ?? new BrainProfile)->hasValue($path))->values()->all(),
            'allSites' => Website::query()->orderBy('name')->get(['id', 'name']),
            'reliances' => Brain::RELIANCE, 'risks' => Brain::RISK,
            'canEdit' => static::canManage(),
            'buildReport' => (array) (((array) $b->settings)['build_report'] ?? []),
            'profileReview' => (array) (((array) $b->settings)['profile_review'] ?? []),
        ]);
    }
}
