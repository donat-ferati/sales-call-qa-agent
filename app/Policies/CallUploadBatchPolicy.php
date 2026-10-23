<?php

namespace App\Policies;

use App\Models\CallUploadBatch;
use App\Models\User;

class CallUploadBatchPolicy
{
    public function before(User $user): bool
    {
        return in_array($user->role, ['admin', 'manager'], true);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, CallUploadBatch $batch): bool
    {
        return false;
    }

    public function retry(User $user, CallUploadBatch $batch): bool
    {
        return false;
    }
}
