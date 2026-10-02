<?php

namespace App\Http\Requests;

use App\Enums\LocationTypeEnum;
use App\Models\Location;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class LocationRequest extends BaseRequest
{
    public function authorizeAction($action): bool
    {
        $user = $this->user();

        return match ($action) {
            'store' => $user->can('create', Location::class),
            'update', 'edit', 'destroy' => $user->can('update', $this->route('location')),
            default => false
        };
    }

    public function validateAction($action): array
    {
        switch ($action) {
            case 'store':
                return [
                    'name' => ['required', 'unique:locations'],
                    'type' => ['required', 'string', new Enum(LocationTypeEnum::class)],
                    'status' => ['required', 'integer', 'in:0,1'],
                    'areas' => ['nullable', 'array'],
                    'areas.*.id' => ['nullable', 'exists:areas,id'],
                    'default_area_id' => ['nullable', 'exists:areas,id'],
                    'default_wholesaleout_location' => ['nullable', 'boolean'],
                ];

            case 'update':
                return [
                    'name' => ['required', Rule::unique(Location::class)->ignore($this->id)],
                    'type' => ['required', 'string', new Enum(LocationTypeEnum::class)],
                    'status' => ['required', 'integer', 'in:0,1'],
                    'areas' => ['nullable', 'array'],
                    'areas.*.id' => ['nullable', 'exists:areas,id'],
                    'default_area_id' => ['nullable', 'exists:areas,id'],
                    'default_wholesaleout_location' => ['nullable', 'boolean'],
                ];

            default:
                return [];
        }
    }
}
