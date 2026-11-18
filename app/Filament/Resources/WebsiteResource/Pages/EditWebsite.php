<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use Filament\Actions;
use App\Filament\Support\Gsc\GscConnectionCheck;
use App\Filament\Support\Gsc\GscOAuth;
use App\Models\GscConnection;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditWebsite extends EditRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('gscCheck')->label('Kiểm tra Search Console')->icon('heroicon-o-signal')->color('gray')
                ->action(function (): void {
                    abort_unless(WebsiteResource::isAdmin(), 403);
                    $r = GscConnectionCheck::run($this->record);
                    $n = Notification::make()->title('Search Console: '.$r['property'])->body($r['message']);
                    match ($r['level']) {
                        'ok' => $n->success(),
                        'empty' => $n->warning(),
                        default => $n->danger(),
                    };
                    $n->persistent()->send();
                    $this->refreshFormData([]);
                }),
            Actions\Action::make('gscConnect')->label('Kết nối Google')->icon('heroicon-o-link')
                ->visible(fn (): bool => GscOAuth::configured())
                ->url(fn (): string => route('gsc.oauth.start', ['website' => $this->record->getKey()])),
            Actions\Action::make('gscDisconnect')->label('Thu hồi kết nối Google')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (): bool => GscConnection::active() !== null)
                ->requiresConfirmation()
                ->modalDescription('Kết nối Google dùng chung cho mọi website: thu hồi ở đây thì các website khác đang dùng kết nối này cũng mất số liệu Search Console.')
                ->action(function (): void {
                    abort_unless(WebsiteResource::isAdmin(), 403);
                    GscOAuth::disconnect();
                    Notification::make()->title('Đã thu hồi kết nối Google')->success()->send();
                }),
        ];
    }

    // Không nạp credential đã giải mã vào form và không cho ghi đè qua trang sửa.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['encrypted_credentials']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['encrypted_credentials'], $data['credentials_input']);

        return $data;
    }
}
