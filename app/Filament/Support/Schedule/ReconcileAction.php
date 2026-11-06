<?php

namespace App\Filament\Support\Schedule;

use App\Models\Publication;
use Filament\Actions\Action as PageAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Support\Facades\Auth;

final class ReconcileAction
{
    public const DESCRIPTION = 'Hệ thống sẽ hỏi CMS theo khóa xuất bản xem bài đã tồn tại chưa. Thao tác này KHÔNG đăng lại và không tạo bài mới.';

    public static function run(Publication $record): void
    {
        $result = ReconcileRequest::request($record, Auth::user());
        Notification::make()
            ->title($result['ok'] ? 'Đối soát' : 'Không thể đối soát')
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    public static function visible(?Publication $record): bool
    {
        return $record !== null && ReconcileRequest::reasonUnavailable(Auth::user(), $record) === null;
    }

    public static function page(): PageAction
    {
        return PageAction::make('reconcile')
            ->label('Đối soát ngay')
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Đối soát lượt đăng')
            ->modalDescription(self::DESCRIPTION)
            ->visible(fn (Publication $record): bool => self::visible($record))
            ->action(fn (Publication $record) => self::run($record));
    }

    public static function row(): TableAction
    {
        return TableAction::make('reconcile')
            ->label('Đối soát ngay')
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Đối soát lượt đăng')
            ->modalDescription(self::DESCRIPTION)
            ->visible(fn (Publication $record): bool => self::visible($record))
            ->action(fn (Publication $record) => self::run($record));
    }
}
