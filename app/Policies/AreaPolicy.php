<?php

namespace App\Policies;

use App\Models\Area;
use App\Models\User;

class AreaPolicy
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
     * Determine whether the user can view the area.
     */
    public function view(User $user, Area $area): bool
    {
        // Check if the area is connected to user's locations
        return $user->locations()
            ->whereExists(function ($query) use ($area) {
                $query->from('area_location')
                    ->whereColumn('area_location.location_id', 'locations.id')
                    ->where('area_location.area_id', $area->id);
            })
            ->exists();
    }

    /**
     * Determine whether the user can create areas.
     */
    public function create(User $user): bool
    {
        return false; // Only users with global permissions (checked in before()) can create
    }

    /**
     * Determine whether the user can update the area.
     */
    public function update(User $user, Area $area): bool
    {
        return $this->view($user, $area);
    }

    /**
     * Determine whether the user can delete the area.
     */
    public function delete(User $user, Area $area): bool
    {
        return $this->view($user, $area);
    }
}
