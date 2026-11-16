<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ảnh đại diện chữ cái dựng CỤC BỘ (SVG data URI). Thay UiAvatarsProvider của Filament để không gửi tên/IP người dùng ra ui-avatars.com.
 */
class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));
        $letters = collect(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $w) => mb_strtoupper(mb_substr($w, 0, 1)))
            ->filter(fn (string $c) => preg_match('/^[\p{L}\p{N}]$/u', $c) === 1)
            ->take(2)->implode('');
        $letters = $letters !== '' ? $letters : '?';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#0f6b5c"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="Arial,sans-serif" font-size="26" fill="#ffffff">'
            .htmlspecialchars($letters, ENT_QUOTES | ENT_XML1, 'UTF-8').'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
