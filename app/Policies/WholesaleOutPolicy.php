<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WholesaleOut;

class WholesaleOutPolicy
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
     * Determine whether the user can view the wholesale out.
     */
    public function view(User $user, WholesaleOut $wholesaleOut): bool
    {
        // Check if the wholesale out's area is connected to user's locations
        return $user->locations()
            ->whereExists(function ($query) use ($wholesaleOut) {
                $query->from('area_location')
                    ->whereColumn('area_location.location_id', 'locations.id')
                    ->where('area_location.area_id', $wholesaleOut->area_id);
            })
            ->exists();
    }

    /**
     * Determine whether the user can create wholesale outs.
     */
    public function create(User $user): bool
    {
        return false; // Only users with global permissions (checked in before()) can create
    }

    /**
     * Determine whether the user can update the wholesale out.
     */
    public function update(User $user, WholesaleOut $wholesaleOut): bool
    {
        return $this->view($user, $wholesaleOut);
    }

    /**
     * Determine whether the user can delete the wholesale out.
     */
    public function delete(User $user, WholesaleOut $wholesaleOut): bool
    {
        return $this->view($user, $wholesaleOut);
    }
}
