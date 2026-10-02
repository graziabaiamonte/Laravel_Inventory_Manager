<?php

namespace App\Http\Requests;

use App\Models\Stock;
use App\Traits\Helpers;

class BackorderRequest extends BaseRequest
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
            'update', 'destroy' => $user->can('update', $this->route('backorder')),
            default => false
        };
    }

    public function validateAction($action): array
    {
        if ($action === 'update') {
            $rules['status'] = 'sometimes|integer|in:0,1';

            $rules['records'] = 'nullable|array';
            $rules['records.*.id'] = ['sometimes', 'nullable', 'integer', 'exists:backorder_records,id'];
            $rules['records.*.quantity'] = ['required', 'integer', 'min:1', 'max:'.Stock::MAX_QUANTITY];
            // Add validation for area_quantities field
            // $rules['records.*.area_quantities'] = 'nullable|array';
            // $rules['records.*.area_quantities.*.id'] = 'required|integer|exists:backorder_records_areas,id';
            // $rules['records.*.area_quantities.*.area_id'] = 'required|integer|exists:areas,id';
            // $rules['records.*.area_quantities.*.quantity'] = 'required|integer|min:1';

            // $rules['records.*.area_quantities.*.id'] = 'required|integer|exists:backorder_records_areas,id';
            $rules['records.*.area_quantities'] = 'nullable|array';
            $rules['records.*.area_quantities.*.area_id'] = [
                'required_with:records.*.area_quantities',
                'integer',
                'exists:areas,id',
                function ($attribute, $value, $fail) {
                    if (! $this->validateAreaAccess($value)) {
                        $fail("You don't have access to the selected area.");
                    }
                },
            ];
            $rules['records.*.area_quantities.*.area_name'] = 'nullable|string';
            $rules['records.*.area_quantities.*.quantity'] = 'required_with:records.*.area_quantities|integer|min:1';

        }

        return $rules;
    }
}
