<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Validation\Rule;

class SupplierRequest extends BaseRequest
{
    public function authorizeAction($action): bool
    {
        return true;
    }

    public function validateAction($action): array
    {
        switch ($action) {
            case 'store':
                return [
                    'name' => ['required', 'unique:suppliers'],
                    'address' => ['required', 'string'],
                    'phone' => ['required', 'string'],
                    'email' => ['required', 'email'],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            case 'update':
                return [
                    'name' => ['required', Rule::unique(Supplier::class)->ignore($this->id)],
                    'address' => ['required', 'string'],
                    'phone' => ['required', 'string'],
                    'email' => ['required', 'email'],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            default:
                return [];
        }
    }
}
