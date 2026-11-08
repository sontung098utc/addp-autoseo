<?php

namespace App\Filament\Pages;

use App\Models\BrandProfile;
use App\Models\User;
use App\Models\Website;
use App\Services\Brand\BrandProfileData;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * AG-S19: hồ sơ doanh nghiệp/thương hiệu, MỖI WEBSITE MỘT BẢN (tạo khi lưu lần đầu). Được đọc thật bởi:
 * Chiến lược (phù hợp chủ đề), Người viết (giọng, claim), Biên tập (luật BAN01: từ cấm + đối thủ).
 * Admin và người duyệt sửa; biên tập viên chỉ xem.
 */
class BrandProfilePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Cài đặt';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Hồ sơ doanh nghiệp';

    protected static ?string $title = 'Hồ sơ doanh nghiệp';

    protected static ?string $slug = 'ho-so-doanh-nghiep';

    protected static ?int $navigationSort = 32;

    protected static string $view = 'filament.pages.brand-profile';

    public ?int $websiteId = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isWriter();
    }

    public function canEdit(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    public function mount(): void
    {
        $this->websiteId = Website::query()->orderBy('name')->value('id');
        $this->loadProfile();
    }

    public function updatedWebsiteId(): void
    {
        $this->loadProfile();
    }

    private function loadProfile(): void
    {
        $p = $this->websiteId ? BrandProfile::query()->where('website_id', $this->websiteId)->first() : null;
        $this->form->fill([
            'business_field' => $p?->business_field, 'audience' => $p?->audience, 'voice' => $p?->voice, 'voice_notes' => $p?->voice_notes,
            'forbidden_terms' => $p?->forbidden_terms ?? [], 'allowed_claims' => $p?->allowed_claims ?? [],
            'avoid_competitors' => $p?->avoid_competitors ?? [], 'key_messages' => $p?->key_messages ?? [],
        ]);
    }

    public function form(Form $form): Form
    {
        $tags = fn (string $name, string $label, string $hint, string $ph) => TagsInput::make($name)->label($label)->hintIcon('heroicon-o-information-circle', $hint)->placeholder($ph)
            ->nestedRecursiveRules(['string', 'max:'.BrandProfileData::MAX_ITEM]);

        return $form->disabled(! $this->canEdit())->statePath('data')->schema([
            Section::make('Doanh nghiệp')->schema([
                TextInput::make('business_field')->label('Lĩnh vực & sản phẩm/dịch vụ chính')->maxLength(BrandProfileData::MAX_FIELD)
                    ->hintIcon('heroicon-o-information-circle', 'Ví dụ: "Nội thất gỗ công nghiệp: bàn ghế văn phòng". Chiến lược dùng để cảnh báo chủ đề lạc lĩnh vực.'),
                Textarea::make('audience')->label('Khách hàng mục tiêu')->rows(3)->maxLength(BrandProfileData::MAX_TEXT)
                    ->hintIcon('heroicon-o-information-circle', 'Ai đọc bài: độ tuổi, nhu cầu, mức hiểu biết. Người viết dùng để chọn cách diễn đạt.'),
                $tags('key_messages', 'Thông điệp chính (tối đa '.BrandProfileData::MAX_MESSAGES.')', 'Những ý thương hiệu muốn lặp lại trong bài. Tối đa 5.', 'Nhập rồi Enter')->rules(['array', 'max:'.BrandProfileData::MAX_MESSAGES]),
            ])->columns(1),
            Section::make('Giọng thương hiệu')->schema([
                Select::make('voice')->label('Giọng văn')->options(BrandProfile::VOICES)->placeholder('Chọn giọng văn'),
                Textarea::make('voice_notes')->label('Ghi chú giọng')->rows(2)->maxLength(BrandProfileData::MAX_TEXT)
                    ->hintIcon('heroicon-o-information-circle', 'Ví dụ: xưng "chúng tôi", tránh tiếng lóng, câu ngắn.'),
            ])->columns(1),
            Section::make('Điều cấm & claim')->schema([
                $tags('forbidden_terms', 'Điều/cụm KHÔNG được nói', 'Bài chứa cụm này sẽ bị luật BAN01 chặn (không chỉ dặn AI).', 'Ví dụ: rẻ nhất'),
                $tags('allowed_claims', 'Claim được phép nêu', 'Chỉ những cam kết đã được xác nhận, ví dụ "bảo hành 12 tháng". Người viết không được tự bịa claim ngoài danh sách.', 'Ví dụ: bảo hành 12 tháng'),
                $tags('avoid_competitors', 'Đối thủ cần tránh nhắc tên', 'Tên đối thủ xuất hiện trong bài sẽ bị luật BAN01 chặn.', 'Tên đối thủ'),
            ])->columns(1),
        ]);
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);
        $site = Website::query()->find($this->websiteId);
        if (! $site) {
            throw ValidationException::withMessages(['websiteId' => 'Hãy chọn website.']);
        }
        $raw = $this->data;
        $errors = [];
        foreach (['business_field' => BrandProfileData::MAX_FIELD, 'audience' => BrandProfileData::MAX_TEXT, 'voice_notes' => BrandProfileData::MAX_TEXT] as $k => $max) {
            if (mb_strlen((string) ($raw[$k] ?? '')) > $max) {
                $errors["data.{$k}"] = "Tối đa {$max} ký tự.";
            }
        }
        foreach (BrandProfile::LISTS as $k) {
            foreach ((array) ($raw[$k] ?? []) as $item) {
                if (mb_strlen((string) $item) > BrandProfileData::MAX_ITEM) {
                    $errors["data.{$k}"] = 'Mỗi mục tối đa '.BrandProfileData::MAX_ITEM.' ký tự.';
                }
            }
        }
        if (count((array) ($raw['key_messages'] ?? [])) > BrandProfileData::MAX_MESSAGES) {
            $errors['data.key_messages'] = 'Tối đa '.BrandProfileData::MAX_MESSAGES.' thông điệp.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        BrandProfile::query()->updateOrCreate(['website_id' => $site->id], BrandProfileData::clean($raw) + ['updated_by' => Auth::id()]);
        $this->loadProfile();
        Notification::make()->title('Đã lưu hồ sơ doanh nghiệp')->success()->send();
    }

    protected function getViewData(): array
    {
        $p = $this->websiteId ? BrandProfile::query()->where('website_id', $this->websiteId)->first() : null;

        return [
            'websites' => Website::query()->orderBy('name')->pluck('name', 'id')->all(),
            'completeness' => BrandProfileData::completeness($p),
            'exists' => (bool) $p,
            'canEdit' => $this->canEdit(),
        ];
    }
}
