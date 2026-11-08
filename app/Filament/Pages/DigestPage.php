<?php

namespace App\Filament\Pages;

use App\Filament\Support\Digest\DigestQueries;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/** Bản tin hằng ngày (AG-U19 v1): chỉ đọc, dữ liệu thật; xem DigestQueries. */
class DigestPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationLabel = 'Bản tin';

    protected static ?int $navigationSort = -90;

    protected static ?string $title = 'Bản tin hằng ngày';

    protected static ?string $slug = 'ban-tin';

    protected static string $view = 'filament.pages.digest';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active;
    }

    public function getHeading(): string
    {
        return $this->digest()['headline'];
    }

    public function getSubheading(): ?string
    {
        return $this->digest()['date_label'].' · '.Auth::user()?->name;
    }

    /** @return array<string, mixed> */
    public function digest(): array
    {
        /** @var User $user */
        $user = Auth::user();

        return DigestQueries::build($user);
    }

    protected function getViewData(): array
    {
        return ['d' => $this->digest()];
    }
}
