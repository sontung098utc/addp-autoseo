<?php

namespace App\Policies;

use App\Models\Publication;
use App\Models\User;

class PublicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isApprover();
    }

    public function view(User $user, Publication $publication): bool
    {
        return $user->is_active && $user->isApprover();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Publication $publication): bool
    {
        return false;
    }

    public function delete(User $user, Publication $publication): bool
    {
        return false;
    }
}
