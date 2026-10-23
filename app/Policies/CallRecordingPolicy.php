<?php

namespace App\Policies;

use App\Models\CallRecording;
use App\Models\User;

class CallRecordingPolicy
{
    public function before(User $user): ?bool
    {
        return in_array($user->role, ['admin', 'manager'], true) ? true : false;
    }

    public function view(User $user, CallRecording $record): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }
}
