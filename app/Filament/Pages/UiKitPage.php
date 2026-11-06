<?php

namespace App\Filament\Pages;

use App\Filament\Support\Demo\DemoMode;
use Filament\Pages\Page;

/** Thư viện thành phần UI dùng chung (tab, bảng, màu trạng thái). Chỉ hiện ngoài production, để dev xem và thử. */
class UiKitPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $navigationGroup = 'Cài đặt';

    protected static ?string $navigationLabel = 'Thành phần UI';

    protected static ?string $title = 'Thành phần UI';

    protected static ?string $slug = 'thanh-phan-ui';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.ui-kit';

    public static function canAccess(): bool
    {
        return DemoMode::enabled() && (auth()->user()?->is_active ?? false);
    }

    /** @return array{columns: list<array<string,mixed>>, rows: list<array<string,mixed>>} */
    public function sampleTable(): array
    {
        $columns = [
            ['key' => 'query', 'label' => 'Truy vấn', 'type' => 'text', 'sortable' => true],
            ['key' => 'intent', 'label' => 'Ý định', 'type' => 'badge', 'filter' => true, 'sortable' => true, 'tones' => ['Thông tin' => 'info', 'Thương mại' => 'accent', 'Giao dịch' => 'warn']],
            ['key' => 'clicks', 'label' => 'Clicks', 'type' => 'number', 'sortable' => true],
            ['key' => 'clicks_d', 'label' => 'Δ Clicks', 'type' => 'delta', 'good' => 'up', 'unit' => '%', 'sortable' => true],
            ['key' => 'pos', 'label' => 'Vị trí', 'type' => 'number', 'decimals' => 1, 'sortable' => true, 'visible' => true],
            ['key' => 'pos_d', 'label' => 'Δ Vị trí', 'type' => 'delta', 'good' => 'down', 'decimals' => 1, 'sortable' => true, 'visible' => false],
            ['key' => 'url', 'label' => 'Trang', 'type' => 'link', 'sortable' => true],
        ];
        $rows = [];
        $intents = ['Thông tin', 'Thương mại', 'Giao dịch'];
        foreach (range(1, 40) as $i) {
            $rows[] = [
                'id' => $i,
                'query' => 'học bổng toàn phần '.$i,
                'intent' => $intents[$i % 3],
                'clicks' => 900 - $i * 17,
                'clicks_d' => ($i % 4 === 0) ? -($i % 9) - 2.5 : ($i % 9) + 1.2,
                'pos' => 3 + ($i % 17) * 0.8,
                'pos_d' => ($i % 5 === 0) ? 1.4 : -0.6,
                'url' => ['text' => '/hoc-bong/bai-'.$i, 'href' => '/hoc-bong/bai-'.$i],
                '_tone' => $i % 11 === 0 ? 'danger' : ($i % 7 === 0 ? 'warn' : null),
            ];
        }

        return compact('columns', 'rows');
    }
}
