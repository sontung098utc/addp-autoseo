<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AG-S11: một nhà cung cấp AI. Khóa lưu mã hóa, bị ẩn khỏi mọi serialize, KHÔNG BAO GIỜ hiển thị lại. */
class AiProvider extends Model
{
    public const KINDS = ['gemini' => 'Google Gemini', 'anthropic' => 'Anthropic Claude', 'openai_compat' => 'OpenAI-tương thích'];

    /**
     * Model gợi ý theo kiểu provider và bậc. Chỉ là giá trị khởi tạo có thể sửa trong giao diện — logic không phụ thuộc tên model.
     * openai_compat không có gợi ý vì tên model tùy máy chủ.
     */
    public const SUGGESTED_MODELS = [
        'gemini' => ['cheap' => 'gemini-1.5-flash', 'standard' => 'gemini-1.5-flash', 'reasoning' => 'gemini-1.5-pro'],
        'anthropic' => ['cheap' => 'claude-haiku-5-5', 'standard' => 'claude-sonnet-5-5', 'reasoning' => 'claude-opus-5-5'],
        'openai_compat' => [],
    ];

    protected $fillable = ['name', 'kind', 'base_url', 'api_key', 'monthly_budget_vnd', 'enabled', 'pricing', 'last_test_at', 'last_test_ok', 'last_test_message', 'updated_by'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'monthly_budget_vnd' => 'float', 'enabled' => 'boolean', 'pricing' => 'array', 'last_test_at' => 'datetime', 'last_test_ok' => 'boolean'];
    }

    public function hasKey(): bool
    {
        return ! empty($this->api_key);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
