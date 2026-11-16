<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Agent Studio: hồ sơ tùy chỉnh của một vai trò. Không có dòng = dùng mặc định từ tệp vai trò. */
class AgentProfile extends Model
{
    protected $fillable = ['role_key', 'display_name', 'title', 'avatar', 'traits', 'persona', 'instructions', 'brain_grants', 'version', 'updated_by'];

    protected function casts(): array
    {
        return ['avatar' => 'array', 'traits' => 'array', 'brain_grants' => 'array', 'version' => 'integer'];
    }
}
