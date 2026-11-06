<?php

namespace App\Policies;

use App\Models\ArticleVersion;
use App\Models\User;

class ArticleVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isWriter();
    }

    public function view(User $user, ArticleVersion $version): bool
    {
        return $user->is_active && $user->isWriter();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ArticleVersion $version): bool
    {
        return false;
    }

    public function delete(User $user, ArticleVersion $version): bool
    {
        return false;
    }

    public function approve(User $user, ArticleVersion $version): bool
    {
        return $user->is_active && $user->isApprover();
    }
}
