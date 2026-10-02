<?php

namespace App\Http\Requests;

use App\Enums\RolesEnum;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UserRequest extends BaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorizeAction($action): bool
    {
        // NOTE: Basic auth granted from the middleware in the web routes
        return true;

        // NOTE: the UserPolicy is not used here
        // $user = $this->user();

        // return match ($action) {
        //     'store' => $user->can('store', [User::class, $this->role]),
        //     'edit', 'destroy' => $user->can('edit', $this->route('user')),
        //     'update', => $user->can('update', [$this->route('user'), $this->role]),
        //     default => false
        // };
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function validateAction($action): array
    {
        switch ($action) {
            case 'store':
                return [
                    'name' => ['required'],
                    'last_name' => ['nullable'],
                    'email' => ['required', 'email:strict', 'unique:users'],
                    'password' => ['required', 'confirmed'],
                    'role' => ['required', new Enum(RolesEnum::class)],
                    'status' => ['required', 'integer', 'in:0,1'],
                    'default_location_id' => ['nullable', 'exists:locations,id'],
                ];

            case 'update':
                return [
                    'name' => ['required'],
                    'last_name' => ['nullable'],
                    'email' => ['required', 'email:strict', Rule::unique(User::class)->ignore($this->id)],
                    'password' => ['confirmed'],
                    'status' => ['required', 'integer', 'in:0,1'],
                    'role_value' => [new Enum(RolesEnum::class)],
                    'default_location_id' => ['nullable', 'exists:locations,id'],
                ];

            default:
                return [];
        }

    }
}
