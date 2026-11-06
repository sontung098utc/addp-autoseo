<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isWriter();
    }

    public function view(User $user, Article $article): bool
    {
        return $user->is_active && $user->isWriter();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isWriter();
    }

    public function update(User $user, Article $article): bool
    {
        return $user->is_active && $user->isWriter();
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function review(User $user, Article $article): bool
    {
        return $user->is_active && $user->isApprover();
    }
}
