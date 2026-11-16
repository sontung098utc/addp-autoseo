<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PublicationResource;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/** Địa chỉ cũ /admin/lich-tuan: lịch tuần nay là tab đầu của "Lịch xuất bản". Giữ trang này chỉ để chuyển hướng. */
class WeeklySchedule extends Page
{
    protected static ?string $title = 'Lịch tuần';

    protected static ?string $slug = 'lich-tuan';

    protected static string $view = 'filament.pages.weekly-schedule-redirect';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    public function mount(): void
    {
        $this->redirect(PublicationResource::scheduleUrl(), navigate: false);
    }
}
