<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Validation\Rule;

class CustomerRequest extends BaseRequest
{
    public function authorizeAction($action): bool
    {
        $user = $this->user();

        return match ($action) {
            'store', 'update', 'destroy' => $user->hasAnyPermission(['all']),
            default => false
        };
    }

    public function validateAction($action): array
    {
        switch ($action) {
            case 'store':
                return [
                    'name' => ['required', 'string'],
                    'last_name' => ['nullable', 'string'],
                    'address' => ['required', 'string'],
                    'phone' => ['nullable', 'string'],
                    'email' => ['required', 'email', 'unique:customers'],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            case 'update':
                return [
                    'name' => ['required', 'string'],
                    'last_name' => ['nullable', 'string'],
                    'address' => ['required', 'string'],
                    'phone' => ['nullable', 'string'],
                    'email' => ['required', 'email', Rule::unique(Customer::class)->ignore($this->id)],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            default:
                return [];
        }
    }
}
