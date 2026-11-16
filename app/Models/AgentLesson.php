<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Agent Studio: bài học ngắn của một vai trò, được đưa vào prompt ở mỗi lần chạy khi đang bật. */
class AgentLesson extends Model
{
    protected $fillable = ['role_key', 'text', 'active', 'source', 'run_id', 'created_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
