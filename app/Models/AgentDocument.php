<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Agent Studio: tài liệu riêng của vai trò: kind 'knowledge' (kiến thức) hoặc 'skill' (kỹ năng tùy chỉnh). */
class AgentDocument extends Model
{
    protected $fillable = ['role_key', 'kind', 'title', 'body', 'bytes', 'created_by'];
}
