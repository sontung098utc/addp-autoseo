<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgentRunResource\Pages;
use App\Filament\Support\Schedule\VnTime;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Filament\Support\Agent\RunActivity;
use App\Models\AgentRun;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** AG-S01: nhật ký các phiên chạy của đội AI. Chỉ xem; phiên chỉ do hệ thống tạo. */
class AgentRunResource extends Resource
{
    public const STATUS = [
        'running' => ['Đang chạy', 'info'],
        'ready_for_review' => ['Đã có bản nháp chờ duyệt', 'success'],
        'held' => ['Giữ nháp, cần người xem', 'warning'],
        'blocked' => ['Bị chặn trước khi chạy', 'gray'],
        'failed' => ['Lỗi', 'danger'],
    ];

    protected static ?string $model = AgentRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'Phiên chạy';

    protected static ?string $cluster = \App\Filament\Clusters\AgentTeam::class;

    protected static ?string $slug = 'phien-chay';

    protected static \Filament\Pages\SubNavigationPosition $subNavigationPosition = \Filament\Pages\SubNavigationPosition::Top;

    protected static ?string $modelLabel = 'Phiên chạy';

    protected static ?string $pluralModelLabel = 'Phiên chạy của đội AI';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        $u = Auth::user();

        return $u instanceof \App\Models\User && $u->is_active && $u->isApprover();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['article', 'website', 'steps']))
            ->poll(fn () => RunActivity::anyRunning() ? '3s' : '15s')
            ->defaultSort('id', 'desc')
            ->recordClasses('ss-sticky-row')
            ->columns([
                // Cột nội dung là cột quan trọng nhất: cho rộng; các cột phụ gọn lại, không xuống dòng.
                Tables\Columns\TextColumn::make('article.primary_keyword')->label('Bài / từ khóa')->searchable()->wrap()
                    ->description(fn (AgentRun $r) => $r->website?->name)
                    ->extraHeaderAttributes(['class' => 'ss-col-main'])->extraCellAttributes(['class' => 'ss-col-main']),
                Tables\Columns\TextColumn::make('status')->label('Kết quả')->badge()
                    ->formatStateUsing(fn (string $state, AgentRun $r) => RunActivity::isStale($r) ? 'Có thể bị treo' : (self::STATUS[$state][0] ?? $state))
                    ->color(fn (string $state, AgentRun $r) => RunActivity::isStale($r) ? 'warning' : (self::STATUS[$state][1] ?? 'gray'))
                    ->icon(fn (string $state, AgentRun $r) => RunActivity::isLive($r) ? 'heroicon-o-arrow-path' : null)
                    ->extraAttributes(fn (AgentRun $r) => RunActivity::isLive($r) ? ['class' => 'ss-badge-live', 'data-running' => '1'] : [])
                    ->description(fn (AgentRun $r) => RunActivity::isLive($r) ? 'Đang ở: '.(AgentFlowBlueprint::byStage()[RunActivity::currentStage($r->steps)]['title'] ?? 'hoàn tất') : null)
                    ->tooltip(fn (AgentRun $r) => $r->outcome_reason ?: null)
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'white-space:nowrap']),
                Tables\Columns\TextColumn::make('score')->label('Điểm')->alignCenter()->placeholder('—')
                    ->state(fn (AgentRun $r) => $r->steps->where('stage', 'grade')->last()?->data['score'] ?? null)
                    ->tooltip('Điểm 0–100 của lần chấm gần nhất. Quyết định đạt/không do luật, điểm chỉ để theo dõi.')
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'width:1%']),
                Tables\Columns\TextColumn::make('ai_mode')->label('AI')->visibleFrom('lg')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'real' ? 'AI thật' : 'AI giả lập')
                    ->color(fn (string $state) => $state === 'real' ? 'success' : 'gray')
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'white-space:nowrap']),
                Tables\Columns\TextColumn::make('repair_rounds')->label('Vòng sửa')->alignCenter()->toggleable(isToggledHiddenByDefault: true)
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'width:1%']),
                Tables\Columns\TextColumn::make('cost_vnd')->label('Chi phí')->visibleFrom('lg')->toggleable()->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.').' ₫')
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'white-space:nowrap']),
                Tables\Columns\TextColumn::make('started_at')->label('Bắt đầu (giờ VN)')->sortable()
                    ->formatStateUsing(fn (AgentRun $r) => VnTime::format(VnTime::fromRaw($r, 'started_at')))
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'white-space:nowrap']),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Xem diễn biến')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgentRuns::route('/'),
            'view' => Pages\ViewAgentRun::route('/{record}'),
        ];
    }
}
