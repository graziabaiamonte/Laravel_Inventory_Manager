<?php

namespace App\Policies;

use App\Models\Stock;
use App\Models\User;

class StockPolicy
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
     * Determine whether the user can view the stock.
     */
    public function view(User $user, Stock $stock): bool
    {
        // Check if the stock's area is connected to user's locations
        return $user->locations()
            ->whereExists(function ($query) use ($stock) {
                $query->from('area_location')
                    ->whereColumn('area_location.location_id', 'locations.id')
                    ->where('area_location.area_id', $stock->area_id);
            })
            ->exists();
    }

    /**
     * Determine whether the user can create stocks.
     */
    public function create(User $user): bool
    {
        return false; // Only users with global permissions (checked in before()) can create
    }

    /**
     * Determine whether the user can update the stock.
     */
    public function update(User $user, Stock $stock): bool
    {
        return $this->view($user, $stock);
    }

    /**
     * Determine whether the user can delete the stock.
     */
    public function delete(User $user, Stock $stock): bool
    {
        return $this->view($user, $stock);
    }
}
