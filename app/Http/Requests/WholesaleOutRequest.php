<?php

namespace App\Http\Requests;

use App\Models\Record;
use App\Models\Stock;
use Illuminate\Validation\Rule;

class WholesaleOutRequest extends BaseRequest
{
    use \App\Traits\Helpers;

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('records')) {
            $records = $this->input('records');
            foreach ($records as &$record) {
                if (isset($record['barcode'])) {
                    $record['barcode'] = self::cleanBarcode($record['barcode']);
                }
            }
            $this->merge(['records' => $records]);
        }
    }

    /**
     * Custom validation messages
     */
    public function messages(): array
    {
        $atLeastOneRecord = 'Lo scarico deve contenere almeno un disco.';

        return [
            'records.required' => $atLeastOneRecord,
            'records.min' => $atLeastOneRecord,
        ];
    }

    public function authorizeAction($action): bool
    {
        $user = $this->user();

        return match ($action) {
            'index', 'store' => true,
            'update', 'destroy' => $user->can('update', $this->route('wholesale_out')),
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
            'customer_id' => $isFileUploadStep ? 'nullable|exists:customers,id' : 'required|exists:customers,id',
            'area_id' => [
                'required', // Always required for WholesaleOut operations
                'integer',
                function ($attribute, $value, $fail) {
                    // Validate that area_id is a valid warehouse area
                    if (! \App\Models\Area::defaultWarehouseAreas()->where('id', $value)->exists()) {
                        $fail('The selected area is not a valid warehouse area for wholesale operations.');
                    }
                    // Check if user has access to this area
                    if (! $this->validateAreaAccess($value)) {
                        $fail("You don't have access to the selected area.");
                    }
                },
            ],
            'status' => 'nullable|integer|in:0,1', // 0 = inactive (default), 1 = active
            'doc_num' => [
                $isFileUploadStep ? 'nullable' : 'required',
                'string',
                'max:255',
                Rule::unique('wholesale_outs', 'doc_num')->where(function ($query) {
                    return $query->where('customer_id', $this->input('customer_id'));
                })->ignore($this->route('wholesale_out')?->id),
            ],
            'description' => 'nullable|string',
            'file' => 'nullable|file|mimes:xlsx,xls',

            // DO NOT relax this back to 'nullable|array'.
            //
            // An empty record set is not just a meaningless document: it silently
            // discards the user's edit on ACTIVE WholesaleOuts. handleExistingRecordsForUpdate()
            // defers record deletion to ActiveWholesaleOutReconciliationService, but
            // saveWholesaleOutWithRecords() only runs the reconciliation when the submitted
            // records array is NOT empty. Remove every row from an active WholesaleOut and
            // nobody deletes anything: the request returns success, the records survive and
            // their stock stays allocated (verified: 1 record / 20 units allocated, PUT with
            // records: [] -> HTTP 302, stock unchanged, record still present).
            //
            // This rule is what makes that code path unreachable. Loosening it re-opens the
            // bug without touching a single line of the reconciliation logic.
            //
            // The $isFileUploadStep exemption must stay: the XLS import posts the file
            // before any record exists.
            'records' => $isFileUploadStep ? 'nullable|array' : 'required|array|min:1',
            'records.*.id' => [
                'sometimes', // Allows new records without an ID
                'nullable', // Allow null/empty values for new records
                'integer',
                function ($attribute, $value, $fail) {
                    // Only validate existence if id is provided and not null/empty
                    if ($value !== null && $value !== '' && $value > 0) {
                        $wholesaleOut = $this->route('wholesale_out');
                        $query = \App\Models\WholesaleOutRecord::where('id', $value);

                        // If updating, ensure the record belongs to this wholesale_out
                        if ($wholesaleOut) {
                            $query->where('wholesale_out_id', $wholesaleOut->id);
                        }

                        if (! $query->exists()) {
                            $fail(__('validation.exists', ['attribute' => $attribute]));
                        }
                    }
                },
            ],
            'records.*.record_id' => [ // ID of the actual Record (the item)
                'required', // Make sure this is always present
                'integer',
                'exists:records,id',
            ],
            'records.*.stock_id' => [ // stock_id is now crucial for each record
                'nullable', // Allow null values when area has no stock (backorder scenario)
                'integer',
                function ($attribute, $value, $fail) {
                    // Only validate existence if stock_id is provided and not null/empty
                    if ($value !== null && $value !== '' && $value > 0) {
                        $stock = \App\Models\Stock::find($value);
                        if (! $stock) {
                            $fail(__('validation.exists', ['attribute' => $attribute]));
                        }
                    }
                },
                // Further validation to ensure stock_id belongs to the record_id if necessary
            ],
            'records.*.quantity' => ['required', 'integer', 'min:1', 'max:'.Stock::MAX_QUANTITY],
            'records.*.unit_price' => 'required|numeric|min:0',
            'records.*.discount' => 'nullable|integer|min:0|max:100',
            'records.*.total_price' => 'required|numeric|min:0',
            'records.*.vat' => 'nullable|integer|min:0',

            // Add validation for area_quantities (works for both single and multiple area modes)
            'records.*.area_quantities' => 'nullable|array',
            'records.*.area_quantities.*.area_id' => [
                'required_with:records.*.area_quantities',
                'integer',
                'exists:areas,id',
                function ($attribute, $value, $fail) {
                    if (! $this->validateAreaAccess($value)) {
                        $fail("You don't have access to the selected area.");
                    }
                },
            ],
            'records.*.area_quantities.*.area_name' => 'nullable|string',
            'records.*.area_quantities.*.quantity' => [
                'required_with:records.*.area_quantities',
                'integer',
                'min:1',
                'max:'.Stock::MAX_QUANTITY,
                function ($attribute, $value, $fail) {
                    // Check if user has permission to edit quantities
                    $user = $this->user();
                    if ($user && ! $user->hasAnyPermission(['all', 'edit_wholesaleout_quantities'])) {
                        // For updates, check if quantity has changed
                        if ($this->route('wholesale_out')) {
                            // Extract indices from attribute path: records.0.area_quantities.1.quantity
                            preg_match('/records\.(\d+)\.area_quantities\.(\d+)\.quantity/', $attribute, $matches);
                            if (count($matches) === 3) {
                                $recordIndex = $matches[1];
                                $areaQtyIndex = $matches[2];

                                $recordInput = $this->input("records.{$recordIndex}");
                                if (isset($recordInput['id']) && $recordInput['id']) {
                                    // This is an existing record - check if quantity changed
                                    $wholesaleOutRecord = \App\Models\WholesaleOutRecord::with('wholesaleOutRecordsArea')
                                        ->find($recordInput['id']);

                                    if ($wholesaleOutRecord) {
                                        $areaId = $this->input("records.{$recordIndex}.area_quantities.{$areaQtyIndex}.area_id");
                                        $existingAreaQty = $wholesaleOutRecord->wholesaleOutRecordsArea
                                            ->firstWhere('area_id', $areaId);

                                        if ($existingAreaQty && $existingAreaQty->quantity != $value) {
                                            $fail("You don't have permission to edit quantities.");
                                        }
                                    }
                                }
                            }
                        }
                    }
                },
            ],

            'records.*.cat_number' => 'nullable|string',
            'records.*.barcode' => 'nullable|string',
            'records.*.artist' => 'nullable|string',

            // Add validation for label discounts
            'label_discounts' => 'nullable|array',
            'label_discounts.*.label_id' => 'required_with:label_discounts|integer|exists:labels,id',
            'label_discounts.*.discount' => 'required_with:label_discounts|integer|min:0|max:100',
        ];

        return $rules;
    }
}
