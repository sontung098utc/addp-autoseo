<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSetting extends Model
{
    protected $fillable = ['provider', 'api_key', 'model', 'run_budget_vnd', 'max_repair_rounds', 'disabled_roles', 'last_test_at', 'last_test_ok', 'last_test_message', 'updated_by'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'run_budget_vnd' => 'float', 'disabled_roles' => 'array', 'last_test_at' => 'datetime', 'last_test_ok' => 'boolean'];
    }

    /** Dòng cấu hình duy nhất; chưa có thì trả bản chưa lưu với giá trị mặc định. */
    public static function current(): self
    {
        return static::query()->first() ?? new static(['provider' => 'gemini', 'run_budget_vnd' => 50000, 'max_repair_rounds' => 3]);
    }
}
