<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kết nối Search Console bằng tài khoản Google của người dùng (OAuth, chỉ đọc). Refresh token lưu mã hóa, bị ẩn khỏi mọi serialize.
 * Một kết nối đang dùng tại một thời điểm; kết nối cũ được đánh dấu revoked_at khi kết nối lại hoặc thu hồi.
 */
class GscConnection extends Model
{
    protected $fillable = ['google_email', 'refresh_token', 'connected_by', 'revoked_at', 'last_error'];

    protected $hidden = ['refresh_token'];

    protected function casts(): array
    {
        return ['refresh_token' => 'encrypted', 'revoked_at' => 'datetime'];
    }

    public static function active(): ?self
    {
        return self::whereNull('revoked_at')->latest('id')->first();
    }
}
