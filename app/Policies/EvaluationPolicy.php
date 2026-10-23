<?php

namespace App\Policies;

use App\Models\Evaluation;
use App\Models\User;

class EvaluationPolicy
{
    public function before(User $user): ?bool
    {
        return in_array($user->role, ['admin', 'manager'], true) ? true : false;
    }

    public function view(User $user, Evaluation $record): bool
    {
        return false;
    }

    public function review(User $user, Evaluation $record): bool
    {
        return false;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }
}
