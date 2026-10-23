<?php

namespace App\Policies;

use App\Models\QaTemplate;
use App\Models\User;

class QaTemplatePolicy
{
    public function before(User $user): ?bool
    {
        return in_array($user->role, ['admin', 'manager'], true) ? true : false;
    }

    public function view(User $user, QaTemplate $record): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, QaTemplate $record): bool
    {
        return false;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }
}
