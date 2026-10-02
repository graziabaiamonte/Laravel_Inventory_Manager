<?php

namespace App\Http\Resources;

use App\Enums\PermissionsEnum;
use App\Enums\RolesEnum;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use ValueError;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'status' => $this->status,
            'default_location_id' => $this->default_location_id,
            'locations' => $this->locations,
            'role' => ($role = $this->getRoleNames()->first()) ? RolesEnum::from($role)->getJsonValue() : null,
            'permissions' => $this->getDirectPermissions()
                ->map(function ($permission) {
                    try {
                        return PermissionsEnum::from($permission['name'])->getJsonValue();
                    } catch (ValueError $e) {
                        // @DISABLED-DYNAMIC-PERMS: Dynamic permissions system disabled
                        // // Handle dynamic store permissions
                        // $prefix = 'manage_location_';
                        // if (Str::startsWith($permission['name'], $prefix)) {
                        //     $location = Location::find((int) Str::after($permission['name'], $prefix));
                        //
                        //     return [
                        //         'value' => $prefix.$location->id,
                        //         'label' => 'ManageStore'.$location->id,
                        //         'description' => "Gestione Location: {$location->name}",
                        //     ];
                        // }

                        return;
                    }
                })
                ->filter()
                ->values()
                ->toArray(),
        ];
    }
}
