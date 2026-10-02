<?php

namespace App\Enums;

use App\Models\Location;
use App\Traits\Enums\GetSelectValues;

enum PermissionsEnum: string
{
    use GetSelectValues;

    case All = 'all';
    case ManageUsers = 'manage_users';
    case EditWholesaleOutQuantities = 'edit_wholesaleout_quantities';

    // @DISABLED-DYNAMIC-PERMS: Dynamic location permissions system commented out - using location_user pivot table instead
    // public static function getLocations(): array
    // {
    //     // NOTE: Using the cache here to make it usable as much as you'd use a standard Enum.
    //     // It gets cleaned up when updating the store models, on top of expire daily.
    //
    //     return cache()->remember('location.permissions', 60 * 24, function () {
    //         return Location::all()
    //             ->map(function ($location) {
    //                 return [
    //                     'value' => 'manage_location_'.$location->id,
    //                     'label' => 'ManageLocation'.$location->id,
    //                     'description' => "Gestione Location: {$location->name}",
    //                 ];
    //             })
    //             ->toArray();
    //     });
    // }

    public static function getAll(): array
    {
        // Get static enum permissions
        $staticPermissions = self::getJsonValues();

        // @DISABLED-DYNAMIC-PERMS: Dynamic location permissions disabled
        // $locationPermissions = self::getLocations();
        // return array_merge($staticPermissions, $locationPermissions);

        return $staticPermissions;
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::All => 'Tutti',
            self::ManageUsers => 'Gestione Utenti',
            self::EditWholesaleOutQuantities => 'Modifica Quantità Wholesale Out',
        };
    }
}
