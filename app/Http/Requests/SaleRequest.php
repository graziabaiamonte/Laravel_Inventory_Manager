<?php

namespace App\Http\Requests;

use App\Enums\SaleTypeEnum;
use App\Models\Stock;
use App\Traits\Helpers;
use Illuminate\Validation\Rules\Enum;

class SaleRequest extends BaseRequest
{
    use Helpers;

    public function authorizeAction($action): bool
    {
        $user = $this->user();

        return match ($action) {
            'index', 'store' => true,
            'update', 'destroy' => $user->can('update', $this->route('sale')),
            default => false
        };
    }

    public function validateAction($action): array
    {
        $rules = [
            'location_id' => [
                'required',
                'exists:locations,id',
                function ($attribute, $value, $fail) {
                    if (! $this->validateLocationAccess($value)) {
                        $fail("You don't have access to the selected location.");
                    }
                },
            ],
            'remote_customer_id' => ['nullable', 'integer'],
            'remote_customer_name' => ['nullable', 'string'],
            'type' => ['required', new Enum(SaleTypeEnum::class)],
            'amount' => ['required', 'numeric'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ];

        $rules['records'] = 'nullable|array';
        // Allow id field to be present or absent for new records, validate only if provided and not null.
        // An existing line must belong to the sale being edited, so a new sale can't carry any.
        $rules['records.*.id'] = [
            'sometimes',
            'nullable',
            'integer',
            function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && $value > 0) {
                    $saleId = $this->route('sale')?->id;
                    $exists = $saleId && \App\Models\SaleRecord::where('id', $value)->where('sale_id', $saleId)->exists();
                    if (! $exists) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                }
            },
        ];
        $rules['records.*.record_id'] = ['required', 'integer', 'exists:records,id'];
        // A missing stock_id means the default area of the sale location is used.
        // A given one must be a stock of the same record, in an area the user can access.
        // Lines already saved with that stock keep it: sales often draw on the stock of
        // another shop, and an unchanged line must not block editing the rest of the sale.
        $rules['records.*.stock_id'] = [
            'nullable',
            'integer',
            function ($attribute, $value, $fail) {
                if (! $value) {
                    return;
                }
                $recordId = $this->input(str_replace('.stock_id', '.record_id', $attribute));
                $stock = Stock::find($value);
                if (! $stock || (int) $stock->record_id !== (int) $recordId) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));

                    return;
                }
                if (! $this->validateAreaAccess($stock->area_id) && ! $this->isUnchangedLineStock($attribute, (int) $value)) {
                    $fail("You don't have access to the selected stock area.");
                }
            },
        ];
        $rules['records.*.quantity'] = ['required', 'integer', 'min:1', 'max:'.Stock::MAX_QUANTITY];
        $rules['records.*.vat'] = 'required|integer|min:0';
        $rules['records.*.discount'] = 'required|integer|min:0';
        $rules['records.*.price'] = 'required|numeric|min:0';

        return match ($action) {
            'store', 'update' => $rules,
            default => []
        };
    }

    /**
     * Whether the line at this attribute is an existing line of the edited sale already
     * saved with this stock
     */
    private function isUnchangedLineStock(string $attribute, int $stockId): bool
    {
        $saleId = $this->route('sale')?->id;
        $lineId = (int) $this->input(str_replace('.stock_id', '.id', $attribute));

        return $saleId && $lineId > 0 && \App\Models\SaleRecord::where('id', $lineId)
            ->where('sale_id', $saleId)
            ->where('stock_id', $stockId)
            ->exists();
    }
}
