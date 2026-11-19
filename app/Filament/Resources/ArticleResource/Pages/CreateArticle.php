<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Filament\Support\Planning\DuplicateKeywordException;
use App\Filament\Support\Planning\KeywordDuplicateGuard;
use App\Models\Article;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    public function getTitle(): string|Htmlable
    {
        return request()->query('campaign') ? 'Tạo bài trong chiến dịch' : 'Tạo bài lẻ';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['workflow_status']);
        $data['campaign_id'] = $data['campaign_id'] ?: null; // trống = bài lẻ

        // Ghi người tạo: luật "tự duyệt phải có ghi chú + log" dựa vào created_by, nếu để trống sẽ không bao giờ kích hoạt.
        $data['created_by'] = Auth::id();

        return $data;
    }

    /**
     * Chặn trùng từ khóa chính trên cùng website (cùng luật với nhập file/chiến dịch). Bài cũ ở "Cần sửa" thì cho tạo lại
     * khi người dùng đã tích xác nhận. Kiểm lại trong khóa + giao dịch để hai lần bấm nhanh không tạo hai bài.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $confirmed = (bool) ($this->data['confirm_reopen'] ?? false);
        $site = (int) $data['website_id'];
        $kw = (string) $data['primary_keyword'];
        try {
            return KeywordDuplicateGuard::serialized($site, $kw, function () use ($site, $kw, $data, $confirmed) {
                $dup = KeywordDuplicateGuard::find($site, $kw);
                if ($dup !== null && ! ($confirmed && KeywordDuplicateGuard::isReopenable($dup))) {
                    throw new DuplicateKeywordException($dup->id, $kw);
                }

                return Article::create($data);
            });
        } catch (DuplicateKeywordException $e) {
            $dup = Article::find($e->existingArticleId);
            $d = $dup ? KeywordDuplicateGuard::describe($dup) : null;
            $n = Notification::make()->title('Từ khóa này đã có bài')->danger()
                ->body($d ? 'Bài #'.$d['id'].' (trạng thái: '.$d['status_label'].') đang dùng từ khóa này trên website đã chọn.'.($d['reopenable'] ? ' Tích ô xác nhận nếu vẫn muốn tạo thêm.' : '') : \App\Support\UserFacingError::from($e));
            if ($d && $d['url']) {
                $n->actions([\Filament\Notifications\Actions\Action::make('open')->label('Mở bài đó')->url($d['url'])]);
            }
            $n->send();
            $this->halt();
        }
    }
}
