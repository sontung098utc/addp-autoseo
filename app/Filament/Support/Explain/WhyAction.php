<?php

namespace App\Filament\Support\Explain;

use App\Models\ArticleVersion;
use App\Models\Publication;
use Filament\Actions\Action as PageAction;
use Illuminate\Database\Eloquent\Model;

/** Nút "Vì sao chưa đăng?" mở hộp giải thích (chỉ đọc). Dùng cho lượt đăng và bản bài. */
final class WhyAction
{
    public static function explain(Model $record): array
    {
        return $record instanceof Publication
            ? PublishExplainer::forPublication($record)
            : PublishExplainer::forVersion($record instanceof ArticleVersion ? $record : $record->articleVersion);
    }

    public static function page(): PageAction
    {
        return PageAction::make('why')
            ->label(fn (Model $record): string => $record instanceof Publication && $record->status === 'published' ? 'Xem trạng thái đăng' : 'Vì sao chưa đăng?')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->modalHeading('Vì sao bài này chưa được đăng?')
            ->modalContent(fn (Model $record) => view('filament.explain.why-not-published', ['result' => self::explain($record)]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }
}
