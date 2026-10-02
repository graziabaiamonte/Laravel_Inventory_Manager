<?php

namespace App\Http\Requests;

use App\Models\Area;
use Illuminate\Validation\Rule;

class AreaRequest extends BaseRequest
{
    public function authorizeAction($action): bool
    {
        $user = $this->user();

        return match ($action) {
            'index', 'store' => true,
            'update', 'destroy' => $user->can('update', $this->route('area')),
            default => false
        };
    }

    public function validateAction($action): array
    {
        switch ($action) {
            case 'store':
                return [
                    'name' => ['required', 'unique:areas'],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            case 'update':
                return [
                    'name' => ['required', Rule::unique(Area::class)->ignore($this->id)],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            default:
                return [];
        }
    }
}
