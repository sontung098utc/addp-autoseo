<?php

namespace App\Filament\Support\Gsc;

/** Nguồn token truy cập Search Console: service account (máy-với-máy) hoặc tài khoản Google do người dùng cấp quyền (OAuth). */
interface GscTokenProvider
{
    /** @throws GscFailed */
    public function get(): string;

    /** Tên hiển thị cách đăng nhập, dùng trong thông báo lỗi và màn hình (không chứa bí mật). */
    public function label(): string;

    /** 'service_account' | 'oauth' */
    public function kind(): string;
}
