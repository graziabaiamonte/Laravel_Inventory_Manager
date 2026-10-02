<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WholesaleIn;

class WholesaleInPolicy
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
     * Determine whether the user can view the wholesale in.
     */
    public function view(User $user, WholesaleIn $wholesaleIn): bool
    {
        // Check if the wholesale in's area is connected to user's locations
        return $user->locations()
            ->whereExists(function ($query) use ($wholesaleIn) {
                $query->from('area_location')
                    ->whereColumn('area_location.location_id', 'locations.id')
                    ->where('area_location.area_id', $wholesaleIn->area_id);
            })
            ->exists();
    }

    /**
     * Determine whether the user can create wholesale ins.
     */
    public function create(User $user): bool
    {
        return false; // Only users with global permissions (checked in before()) can create
    }

    /**
     * Determine whether the user can update the wholesale in.
     */
    public function update(User $user, WholesaleIn $wholesaleIn): bool
    {
        return $this->view($user, $wholesaleIn);
    }

    /**
     * Determine whether the user can delete the wholesale in.
     */
    public function delete(User $user, WholesaleIn $wholesaleIn): bool
    {
        return $this->view($user, $wholesaleIn);
    }
}
