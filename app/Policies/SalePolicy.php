<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
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
     * Determine whether the user can view the sale.
     */
    public function view(User $user, Sale $sale): bool
    {
        // Check if the sale's location is assigned to the user
        return $user->locations()->where('locations.id', $sale->location_id)->exists();
    }

    /**
     * Determine whether the user can create sales.
     */
    public function create(User $user): bool
    {
        return false; // Only users with global permissions (checked in before()) can create
    }

    /**
     * Determine whether the user can update the sale.
     */
    public function update(User $user, Sale $sale): bool
    {
        return $this->view($user, $sale);
    }

    /**
     * Determine whether the user can delete the sale.
     */
    public function delete(User $user, Sale $sale): bool
    {
        return $this->view($user, $sale);
    }
}
