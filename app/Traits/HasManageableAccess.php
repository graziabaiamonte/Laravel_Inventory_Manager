<?php

namespace App\Traits;

use App\Models\User;

trait HasManageableAccess
{
    abstract public function canBeManagedBy(User $user): bool;
}
