<?php

namespace App\Filament\Support\Article;

use App\Models\ArticleVersion;
use Filament\Actions\Action;

/** Hộp thoại trượt "So với bản trước", dùng chung cho trang bài và trang chi tiết phiên bản. */
class VersionCompareAction
{
    public static function previous(ArticleVersion $v): ?ArticleVersion
    {
        return ArticleVersion::query()->where('article_id', $v->article_id)->where('version', '<', $v->version)->orderByDesc('version')->first();
    }

    /** @param  \Closure(array): ?ArticleVersion  $resolve nhận arguments của action, trả về bản MỚI cần so */
    public static function make(\Closure $resolve): Action
    {
        return Action::make('compare')->label('So với bản trước')->icon('heroicon-o-arrows-right-left')->color('gray')
            ->slideOver()->modalWidth('5xl')->modalHeading('So với bản trước')
            ->modalSubmitAction(false)->modalCancelAction(false)->modalCloseButton()
            ->modalContent(function (Action $action) use ($resolve) {
                $new = $resolve($action->getArguments());
                $old = $new ? self::previous($new) : null;

                return view('filament.article.version-diff', ['new' => $new, 'old' => $old, 'diff' => $new && $old ? VersionDiff::between($old, $new) : null]);
            });
    }
}
