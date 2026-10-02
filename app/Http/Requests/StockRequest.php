<?php

namespace App\Http\Requests;

use App\Models\Stock;
use App\Traits\Helpers;

class StockRequest extends BaseRequest
{
    use Helpers;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorizeAction($action): bool
    {
        $user = $this->user();

        return match ($action) {
            'index', 'store' => true,
            'update', 'destroy' => $user->can('update', $this->route('stock')),
            default => false
        };
    }

    public function validateAction($action): array
    {
        switch ($action) {
            case 'store':
            case 'update':
                return [
                    'record_id' => ['required', 'integer', 'exists:records,id'],
                    'description' => ['nullable', 'string'],
                    'quantity' => ['required', 'integer', 'min:0', 'max:'.Stock::MAX_QUANTITY],
                    'area_id' => [
                        'required',
                        'integer',
                        'exists:areas,id',
                        function ($attribute, $value, $fail) {
                            if (! $this->validateAreaAccess($value)) {
                                $fail("You don't have access to the selected area.");
                            }
                        },
                    ],
                ];
            default:
                return [];
        }
    }
}
