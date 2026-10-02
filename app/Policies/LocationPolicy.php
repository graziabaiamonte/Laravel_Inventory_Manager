<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

class LocationPolicy
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
     * Determine whether the user can update the store.
     */
    public function update(User $user, Location $location): bool
    {
        // app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->locations()->where('locations.id', $location->id)->exists();
        // $user->hasPermissionTo($location->permission); // Compatibility with dynamic permissions
    }

    /**
     * Determine whether the user can delete the store.
     */
    public function delete(User $user, Location $location): bool
    {
        return $this->update($user, $location);
    }

    /**
     * Determine whether the user can create stores.
     */
    public function create(User $user): bool
    {
        return false; // Only users with global permissions (checked in before()) can create
    }
}
