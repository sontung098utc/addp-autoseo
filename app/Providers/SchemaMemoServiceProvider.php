<?php

namespace App\Providers;

use App\Support\MemoizedSchemaBuilder;
use Illuminate\Support\ServiceProvider;

/** Cắm MemoizedSchemaBuilder vào facade Schema (xem lớp đó để biết cách giữ đúng khi cấu trúc đổi). */
class SchemaMemoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('db.schema', fn ($builder) => new MemoizedSchemaBuilder($builder));
    }
}
