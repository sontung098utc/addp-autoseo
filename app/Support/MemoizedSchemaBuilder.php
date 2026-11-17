<?php

namespace App\Support;

use Illuminate\Database\Schema\Builder;

/**
 * Bọc bộ dựng schema để NHỚ kết quả hasTable/hasColumn/hasColumns trong thời gian ngắn. Ứng dụng kiểm tra "bảng/cột này có chưa" ở nhiều nơi
 * (hàng trăm lần mỗi trang), mà mỗi lần là một truy vấn siêu dữ liệu (trên MySQL đi qua information_schema nên chậm hơn nhiều so với SQLite ở máy dev).
 *
 * An toàn vì: (1) mọi lệnh làm đổi cấu trúc (create, table, drop*, rename...) đi qua đây và xóa bộ nhớ ngay; (2) bộ nhớ hết hạn sau TTL giây,
 * nên tiến trình sống lâu (hàng đợi, lệnh chạy nền) vẫn thấy bảng mới sau khi migration chạy ở tiến trình khác.
 *
 * @mixin Builder
 */
final class MemoizedSchemaBuilder
{
    private const TTL = 20;

    /** @var array<string,array{0:bool,1:int}> */
    private static array $memo = [];

    public function __construct(private Builder $inner) {}

    public static function flush(): void
    {
        self::$memo = [];
    }

    private function remember(string $kind, string $key, \Closure $compute): bool
    {
        $k = $this->inner->getConnection()->getName().'|'.$kind.'|'.strtolower($key); // theo tên kết nối, vì facade có thể tạo bộ dựng mới mỗi lần gọi
        $now = time();
        if (isset(self::$memo[$k]) && $now - self::$memo[$k][1] < self::TTL) {
            return self::$memo[$k][0];
        }
        $v = (bool) $compute();
        self::$memo[$k] = [$v, $now];

        return $v;
    }

    public function hasTable($table): bool
    {
        return $this->remember('t', (string) $table, fn () => $this->inner->hasTable($table));
    }

    public function hasColumn($table, $column): bool
    {
        return $this->remember('c', $table.'.'.$column, fn () => $this->inner->hasColumn($table, $column));
    }

    public function hasColumns($table, array $columns): bool
    {
        return $this->remember('cs', $table.'.'.implode(',', $columns), fn () => $this->inner->hasColumns($table, $columns));
    }

    public function __call(string $method, array $args): mixed
    {
        if (! in_array($method, ['getColumnListing', 'getColumns', 'getTables', 'getViews', 'getIndexes', 'getForeignKeys', 'getColumnType', 'getConnection'], true)) {
            self::$memo = []; // mọi lệnh khác có thể đổi cấu trúc: bỏ nhớ
        }

        return $this->inner->{$method}(...$args);
    }
}
