<?php

namespace App\Policies;

use App\Models\BrandProfile;
use App\Models\User;

/** AG-S19: admin và người duyệt sửa; biên tập viên (writer) chỉ xem. */
class BrandProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isWriter();
    }

    public function view(User $user, BrandProfile $profile): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isApprover();
    }

    public function update(User $user, BrandProfile $profile): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, BrandProfile $profile): bool
    {
        return false;
    }
}
