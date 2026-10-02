<?php

namespace App\Policies;

use App\Enums\RolesEnum;
use App\Models\User;

// NOTE: Not invoked by UserRequest, authorization is enforced by the route middleware
class UserPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasAnyPermission(['all', 'manage_users'])) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can create another user.
     */
    public function store(User $user, $role): bool
    {

        // If not admin, the user can create a new one with his same role only
        return ! $user->hasRole(RolesEnum::ADMIN) && $role &&
            $role === $user->getRoleNames()->first();
    }

    /**
     * Determine whether the user can update the another user.
     */
    public function edit(User $user, User $targetUser): bool
    {

        return ! $user->hasRole(RolesEnum::ADMIN) && ! $targetUser->hasRole(RolesEnum::ADMIN);
    }

    /**
     * Determine whether the user can update the another user.
     */
    public function update(User $user, User $targetUser, $role): bool
    {

        return ! $user->hasRole(RolesEnum::ADMIN) && ! $targetUser->hasRole(RolesEnum::ADMIN);
    }
}
