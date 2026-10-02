<?php

namespace App\Policies;

use App\Models\Backorder;
use App\Models\User;

class BackorderPolicy
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
     * Determine whether the user can view the backorder.
     */
    public function view(User $user, Backorder $backorder): bool
    {
        // Check if the backorder's wholesale out area is connected to user's locations
        return $backorder->wholesaleOut && $user->locations()
            ->whereExists(function ($query) use ($backorder) {
                $query->from('area_location')
                    ->whereColumn('area_location.location_id', 'locations.id')
                    ->where('area_location.area_id', $backorder->wholesaleOut->area_id);
            })
            ->exists();
    }

    /**
     * Determine whether the user can create backorders.
     */
    public function create(User $user): bool
    {
        return false; // Only users with global permissions (checked in before()) can create
    }

    /**
     * Determine whether the user can update the backorder.
     */
    public function update(User $user, Backorder $backorder): bool
    {
        return $this->view($user, $backorder);
    }

    /**
     * Determine whether the user can delete the backorder.
     */
    public function delete(User $user, Backorder $backorder): bool
    {
        return $this->view($user, $backorder);
    }
}
