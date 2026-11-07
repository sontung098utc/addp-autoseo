<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgentRunResource\Pages;
use App\Filament\Support\Schedule\VnTime;
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

    protected static ?string $navigationLabel = 'Phiên chạy của đội AI';

    protected static ?string $modelLabel = 'Phiên chạy';

    protected static ?string $pluralModelLabel = 'Phiên chạy của đội AI';

    protected static ?string $navigationGroup = 'Chiến dịch';

    protected static ?int $navigationSort = 5;

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
            ->modifyQueryUsing(fn ($query) => $query->with(['article', 'website']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('article.primary_keyword')->label('Bài / từ khóa')->searchable()->wrap()
                    ->description(fn (AgentRun $r) => $r->website?->name),
                Tables\Columns\TextColumn::make('status')->label('Kết quả')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUS[$state][0] ?? $state)
                    ->color(fn (string $state) => self::STATUS[$state][1] ?? 'gray')
                    ->description(fn (AgentRun $r) => $r->outcome_reason ? \Illuminate\Support\Str::limit($r->outcome_reason, 70) : null),
                Tables\Columns\TextColumn::make('ai_mode')->label('AI')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'real' ? 'AI thật' : 'AI giả lập')
                    ->color(fn (string $state) => $state === 'real' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('repair_rounds')->label('Vòng sửa')->alignCenter(),
                Tables\Columns\TextColumn::make('cost_vnd')->label('Chi phí')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.').' ₫'),
                Tables\Columns\TextColumn::make('started_at')->label('Bắt đầu (giờ VN)')
                    ->formatStateUsing(fn (AgentRun $r) => VnTime::format(VnTime::fromRaw($r, 'started_at')))->sortable(),
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
