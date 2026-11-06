<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

class WebsitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function view(User $user, Website $website): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function update(User $user, Website $website): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function delete(User $user, Website $website): bool
    {
        return false;
    }
}
