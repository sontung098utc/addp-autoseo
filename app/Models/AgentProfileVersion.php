<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Agent Studio: ảnh chụp một phiên bản hồ sơ vai trò (version 0 = mặc định từ tệp trước lần tùy chỉnh đầu). */
class AgentProfileVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['role_key', 'version', 'snapshot', 'changed_by'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }
}
