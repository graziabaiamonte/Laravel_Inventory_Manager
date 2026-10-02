<?php

namespace App\Policies;

use App\Models\Record;
use App\Models\User;

class RecordPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasAnyPermission(['all'])) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view the record.
     */
    public function view(User $user, Record $record): bool
    {
        // All authenticated users (managers, operators) can view all records
        // Stock modification permissions are handled at the controller level
        return true;
    }

    /**
     * Determine whether the user can create records.
     */
    public function create(User $user): bool
    {
        return false; // Only users with global permissions (checked in before()) can create
    }

    /**
     * Determine whether the user can update the record.
     */
    public function update(User $user, Record $record): bool
    {
        return $this->view($user, $record);
    }

    /**
     * Determine whether the user can delete the record.
     */
    public function delete(User $user, Record $record): bool
    {
        return $this->view($user, $record);
    }
}
