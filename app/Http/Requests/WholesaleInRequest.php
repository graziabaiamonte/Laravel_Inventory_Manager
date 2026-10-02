<?php

namespace App\Http\Requests;

use App\Models\Record;
use App\Models\Stock;
use App\Rules\AlphanumericBarcode;
use App\Traits\Helpers;

class WholesaleInRequest extends BaseRequest
{
    use Helpers;

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Clean up barcodes in the records array
        if ($this->has('records')) {
            $records = $this->input('records', []);
            foreach ($records as $index => $record) {
                if (isset($record['barcode'])) {
                    $records[$index]['barcode'] = self::cleanBarcode($record['barcode']);
                }
            }
            $this->merge(['records' => $records]);
        }
    }

    public function authorizeAction($action): bool
    {
        $user = $this->user();

        return match ($action) {
            'index', 'store' => true,
            'update', 'destroy' => $user->can('update', $this->route('wholesale_in')),
            default => false
        };
    }

    public function validateAction($action): array
    {
        // Determine if this is a file upload step (first submission) vs final data submission (second step)
        //
        // INTENTIONAL INCONSISTENCY between store and update modes:
        //
        // CREATE MODE (store): We check both hasFile('file') AND empty('records') because:
        // - User starts with completely empty form
        // - File upload step: file present + no records yet = make required fields optional
        // - Final step: file cleared + records populated = require all fields
        //
        // EDIT MODE (update): We only check hasFile('file') because:
        // - User starts with existing data already populated in form
        // - File upload step: file present = make required fields optional (preserve existing data)
        // - Final step: file cleared by frontend + records from import = require all fields
        // - Checking empty('records') would be unreliable since existing records might still be in form state
        //
        // This difference reflects the distinct workflows: create starts empty, edit starts populated.
        $isFileUploadStep = ($action === 'store' && $this->hasFile('file') && empty($this->input('records'))) ||
                           ($action === 'update' && $this->hasFile('file'));

        $rules = [
            'supplier_id' => $isFileUploadStep ? 'nullable|exists:suppliers,id' : 'required|exists:suppliers,id',
            'area_id' => [
                'required',
                'exists:areas,id',
                function ($attribute, $value, $fail) {
                    if (! $this->validateAreaAccess($value)) {
                        $fail("You don't have access to the selected area.");
                    }
                },
            ],
            'total_price' => 'nullable|numeric|min:0',
            'doc_num' => $isFileUploadStep ? 'nullable|string' : 'required|string',
            'description' => 'nullable|string',
            'status' => $isFileUploadStep ? 'nullable|integer|between:0,1' : 'required|integer|between:0,1',
            'file' => 'nullable|file|mimes:xlsx,xls',
        ];

        $rules['records'] = 'nullable|array';
        // Allow id field to be present or absent for new records, validate only if provided and not null
        $rules['records.*.id'] = [
            'sometimes',
            'nullable',
            'integer',
            function ($attribute, $value, $fail) {
                // Only validate existence if id is provided and not null/empty
                if ($value !== null && $value !== '' && $value > 0) {
                    $exists = \App\Models\WholesaleInRecord::where('id', $value)->exists();
                    if (! $exists) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                }
            },
        ];
        $rules['records.*.quantity'] = [
            'required',
            'integer',
            'max:'.Stock::MAX_QUANTITY,
            function ($attribute, $value, $fail) {
                // Get the area quantities for this record
                $recordIndex = explode('.', $attribute)[1];
                $areaQuantities = $this->input("records.{$recordIndex}.area_quantities", []);
                $totalAreaQuantity = array_sum(array_column($areaQuantities, 'quantity'));

                // Allow quantity = 0 if area quantities sum > 0
                if ($value <= 0 && $totalAreaQuantity <= 0) {
                    $fail('The quantity must be at least 1 or have area quantities.');
                }
            },
        ];

        $rules['records.*.unit_price'] = 'required|numeric|min:0';
        $rules['records.*.discount'] = 'required|integer|min:0|max:100';
        $rules['records.*.total_price'] = 'required|numeric|min:0';
        $rules['records.*.vat'] = 'required|integer|min:0';
        $rules['records.*.retail_price'] = 'nullable|numeric|min:0';
        $rules['records.*.wholesale_price'] = 'nullable|numeric|min:0';
        $rules['records.*.purchase_price'] = 'nullable|numeric|min:0';

        // Add validation for Discogs integration fields
        $rules['records.*.for_sale_on_discogs'] = 'nullable|boolean';
        $rules['records.*.discogs_id'] = 'nullable|integer|min:1';
        // Release ID (Discogs) supplied via import for new records; common to store and update
        $rules['records.*.release_id'] = 'nullable|integer|min:1';

        // Add validation for condition fields
        $rules['records.*.condition_disk'] = 'nullable|string';
        $rules['records.*.condition_cover'] = 'nullable|string';

        // Add validation for area_quantities field
        $rules['records.*.area_quantities'] = 'nullable|array';
        $rules['records.*.area_quantities.*.area_id'] = [
            'required',
            'integer',
            'exists:areas,id',
            function ($attribute, $value, $fail) {
                if (! $this->validateAreaAccess($value)) {
                    $fail("You don't have access to the selected area.");
                }
            },
        ];
        $rules['records.*.area_quantities.*.area_name'] = 'nullable|string'; // Make area_name optional
        $rules['records.*.area_quantities.*.quantity'] = ['required', 'integer', 'min:1', 'max:'.Stock::MAX_QUANTITY];

        if ($action === 'store') {
            $rules['records.*.record_id'] = ['required', 'numeric', function ($attribute, $value, $fail) {
                if ((int) $value !== 0 && ! Record::where('id', $value)->exists()) {
                    $fail(__('validation.exists', ['attribute' => 'record']));
                }
            }];
            $rules['records.*.cat_number'] = 'nullable|string';
            $rules['records.*.artist'] = 'nullable|string';
            $rules['records.*.title'] = 'nullable|string';
            $rules['records.*.format'] = 'nullable|string';
            $rules['records.*.label'] = 'nullable|string';
            $rules['records.*.barcode'] = ['nullable', 'string', new AlphanumericBarcode];
        } elseif ($action === 'update') {
            $rules['records.*.id'] = [
                'sometimes',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    if ($value !== null && (int) $value > 0) {
                        $wholesaleInBeingUpdated = $this->route('wholesale_in');

                        if (! $wholesaleInBeingUpdated) {
                            $fail('Could not verify ownership of the record line item.');

                            return;
                        }

                        $exists = \App\Models\WholesaleInRecord::where('id', $value)
                            ->where('wholesale_in_id', $wholesaleInBeingUpdated->id)
                            ->exists();
                        if (! $exists) {
                            $fail(__('validation.exists', ['attribute' => $attribute]));
                        }
                    }
                },
            ];

            // Allow record_id to be 0 (for new records) or must exist in records table
            $rules['records.*.record_id'] = ['required', 'numeric', function ($attribute, $value, $fail) {
                if ((int) $value !== 0 && ! Record::where('id', $value)->exists()) {
                    $fail(__('validation.exists', ['attribute' => 'record']));
                }
            }];

            // Add validation rules for new record creation (when record_id is 0)
            $rules['records.*.cat_number'] = 'nullable|string';
            $rules['records.*.artist'] = 'nullable|string';
            $rules['records.*.title'] = 'nullable|string';
            $rules['records.*.format'] = 'nullable|string';
            $rules['records.*.label'] = 'nullable|string';
            $rules['records.*.barcode'] = ['nullable', 'string', new AlphanumericBarcode];
        }

        return $rules;
    }
}
