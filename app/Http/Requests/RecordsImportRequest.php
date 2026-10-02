<?php

namespace App\Http\Requests;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Models\Record;
use App\Rules\AlphanumericBarcode;
use App\Traits\LogsToChannel;
use Illuminate\Validation\Rule;

class RecordsImportRequest extends BaseRequest
{
    use \App\Traits\Helpers;
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'imports';
    }

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
     * Determine if the user is authorized to make this request.
     */
    public function authorizeAction($action): bool
    {
        switch ($action) {
            case 'store':
            case 'update':
            case 'destroy':
            case 'updateRecord':
            case 'deleteRecord':
            case 'addRecord':
                return true;
        }

        return false;
    }

    public function validateAction($action): array
    {
        $this->logDetail('VALIDATION ACTION:', ['action' => $action]);

        if ($action === 'store') {
            $this->logDetail('Records before validation:', [
                'records' => $this->input('records'),
                'has_file' => $this->hasFile('file'),
            ]);

            $rules = [
                // 'file' => 'required|file|mimes:xlsx,xls',
                'file' => 'file|mimes:xlsx,xls',
            ];

            // Aggiungi validazioni per i campi di conferma eliminazione
            $rules['confirm_delete'] = 'sometimes|boolean';
            $rules['records_to_delete'] = 'sometimes|array';
            $rules['records_to_delete.*.record_id'] = 'required_with:records_to_delete|integer|exists:records,id';
            $rules['records_to_delete.*.actions'] = 'required_with:records_to_delete|array';
            $rules['records_to_delete.*.actions.*'] = [
                'required',
                'string',
                Rule::in(['delete', 'soft_delete', 'd_delete']),
            ];

        }

        // $rules['records'] = 'nullable|array';
        $rules['records'] = [
            'required_without:file',
            'array',
        ];

        // Global rules that apply to all actions - these will be overridden by action-specific rules if needed
        $rules['records.*.retail_price'] = 'nullable|numeric|min:0';
        $rules['records.*.wholesale_price'] = 'nullable|numeric|min:0';
        $rules['records.*.purchase_price'] = 'nullable|numeric|min:0';
        $rules['records.*.release_id'] = 'nullable|numeric|min:0'; // Add release_id validation
        $rules['records.*.supplier'] = 'nullable|string';
        $rules['records.*.soft_delete'] = 'nullable|boolean';
        $rules['records.*.delete'] = 'nullable|boolean';
        $rules['records.*.d_delete'] = 'nullable|boolean';
        $rules['records.*.stocks_tmp'] = 'nullable|string';

        if ($action === 'store') {
            // $rules['records.*.record_id'] = ['required', 'numeric', function ($attribute, $value, $fail) {
            //     if ((int) $value !== 0 && ! Record::where('id', $value)->exists()) {
            //         $fail(__('validation.exists', ['attribute' => 'record']));
            //     }
            // }];

            $rules['records.*.cat_number'] = [
                'nullable',
                'string',
                'distinct:strict',
            ];

            $rules['records.*.barcode'] = [
                'nullable',
                'string',
                'distinct:strict',
                new AlphanumericBarcode,
            ];

            // $rules['records.*.record_id'] = ['required', 'numeric'];
            $rules['records.*.record_id'] = [
                'nullable',
                'numeric',
                function ($attribute, $value, $fail) {
                    // Only validate existence if record_id is not null and not 0 (0 means new record)
                    if ($value !== null && (int) $value !== 0 && ! Record::where('id', $value)->exists()) {
                        // Get the record details from the request data for a more informative error message
                        $recordIndex = (int) explode('.', $attribute)[1];
                        $records = $this->input('records', []);
                        $recordData = $records[$recordIndex] ?? [];

                        $recordInfo = [];
                        if (! empty($recordData['title'])) {
                            $recordInfo[] = "Title: '{$recordData['title']}'";
                        }
                        if (! empty($recordData['cat_number'])) {
                            $recordInfo[] = "Cat#: '{$recordData['cat_number']}'";
                        }
                        if (! empty($recordData['barcode'])) {
                            $recordInfo[] = "Barcode: '{$recordData['barcode']}'";
                        }

                        $recordDetails = ! empty($recordInfo) ? ' ('.implode(', ', $recordInfo).')' : '';

                        $fail("Record ID {$value} not found in database{$recordDetails}");
                    }
                },
            ];

            // Only apply strict validation rules if NOT in draft mode or if no route context
            if (! $this->route('records_import') || ! $this->route('records_import')->draft) {
                // $rules['records.*.artist'] = 'required|string';
                // $rules['records.*.format'] = 'required|string';
                // $rules['records.*.label'] = 'required|string';
                $rules['records.*.artist'] = ['required_if:records.*.record_id,0', 'string'];
                $rules['records.*.format'] = ['required_if:records.*.record_id,0', 'string'];
                $rules['records.*.label'] = ['required_if:records.*.record_id,0', 'string'];
                $rules['records.*.title'] = ['required_if:records.*.record_id,0', 'string'];
            }

            $rules['records.*.condition_disk'] = [
                'nullable',
                'string',
                Rule::in(array_map(fn ($case) => $case->getDescription(), DiskStatusEnum::cases())),
            ];

            $rules['records.*.condition_cover'] = [
                'nullable',
                'string',
                Rule::in(array_map(fn ($case) => $case->getDescription(), CoverStatusEnum::cases())),
            ];

            $rules['records.*.comments'] = 'nullable|string';
            $rules['records.*.description'] = 'nullable|string';
            $rules['records.*.title'] = 'nullable|string';

        } elseif ($action === 'update') {
            $this->logDetail('Processing UPDATE action validation rules');

            $rules['draft'] = ['required', 'boolean'];

            // Override ALL record validation rules for update action
            $rules['records'] = 'sometimes|array';
            $rules['records.*.id'] = 'sometimes|integer|exists:records_import_record_tmp,id';
            $rules['records.*.barcode'] = 'sometimes|nullable|string';
            $rules['records.*.cat_number'] = 'sometimes|nullable|string';
            $rules['records.*.title'] = 'sometimes|required|string';
            $rules['records.*.release_id'] = 'sometimes|nullable|numeric|min:0'; // Add release_id validation for update
            $rules['records.*.retail_price'] = 'sometimes|nullable|numeric|min:0';
            $rules['records.*.wholesale_price'] = 'sometimes|nullable|numeric|min:0';
            $rules['records.*.purchase_price'] = 'sometimes|nullable|numeric|min:0';
            $rules['records.*.artist'] = [
                'sometimes',
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($value !== null && ! is_string($value) && ! is_array($value)) {
                        $fail("The $attribute field must be a string or object.");
                    }
                    if (is_array($value) && ! isset($value['name'])) {
                        $fail("The $attribute object must have a 'name' field.");
                    }
                },
            ];
            $rules['records.*.format'] = [
                'sometimes',
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($value !== null && ! is_string($value) && ! is_array($value)) {
                        $fail("The $attribute field must be a string or object.");
                    }
                    if (is_array($value) && ! isset($value['name'])) {
                        $fail("The $attribute object must have a 'name' field.");
                    }
                },
            ];
            $rules['records.*.label'] = [
                'sometimes',
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($value !== null && ! is_string($value) && ! is_array($value)) {
                        $fail("The $attribute field must be a string or object.");
                    }
                    if (is_array($value) && ! isset($value['name'])) {
                        $fail("The $attribute object must have a 'name' field.");
                    }
                },
            ];
            $rules['records.*.description'] = 'sometimes|nullable|string';
            $rules['records.*.comments'] = 'sometimes|nullable|string';
            $rules['records.*.supplier'] = 'sometimes|nullable|string';
            $rules['records.*.soft_delete'] = 'sometimes|nullable|boolean';
            $rules['records.*.delete'] = 'sometimes|nullable|boolean';
            $rules['records.*.d_delete'] = 'sometimes|nullable|boolean';

            $this->logDetail('UPDATE rules set for artist/format/label', [
                'artist_rule' => 'custom validation (string or object)',
                'format_rule' => 'custom validation (string or object)',
                'label_rule' => 'custom validation (string or object)',
            ]);

            // Only allow records to be updated if the import is in draft mode
            if ($this->route('records_import') && ! $this->route('records_import')->draft && $this->has('records')) {
                $rules['records'] = [
                    'prohibited',
                    function ($attribute, $value, $fail) {
                        $fail('Cannot edit records after import has been published.');
                    },
                ];
            }

            // Record ID validation for update action
            $rules['records.*.record_id'] = [
                'nullable',
                'numeric',
                function ($attribute, $value, $fail) {
                    // Only validate existence if record_id is not null and not 0 (0 means new record)
                    if ($value !== null && (int) $value !== 0 && ! Record::where('id', $value)->exists()) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ];
        }

        // Add validation rules for single record update (updateRecord method)
        if ($this->route() && $this->route()->getName() === 'records-import.update-record') {
            $rules = [
                'barcode' => ['sometimes', 'string', new AlphanumericBarcode],
                'cat_number' => 'sometimes|string',
                'title' => 'sometimes|required|string',
                'release_id' => 'sometimes|nullable|numeric|min:0', // Add release_id validation for update record
                'retail_price' => 'sometimes|nullable|numeric|min:0',
                'wholesale_price' => 'sometimes|nullable|numeric|min:0',
                'purchase_price' => 'sometimes|nullable|numeric|min:0',
                'artist' => 'sometimes|string',
                'format' => 'sometimes|string',
                'label' => 'sometimes|string',
                'description' => 'sometimes|nullable|string',
                'comments' => 'sometimes|nullable|string',
                'record_id' => [
                    'sometimes',
                    'nullable',
                    'numeric',
                    function ($attribute, $value, $fail) {
                        // Only validate existence if record_id is not null and not 0 (0 means new record)
                        if ($value !== null && (int) $value !== 0 && ! Record::where('id', $value)->exists()) {
                            $fail(__('validation.exists', ['attribute' => $attribute]));
                        }
                    },
                ],
                'condition_disk' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::in(array_map(fn ($case) => $case->getDescription(), DiskStatusEnum::cases())),
                ],
                'condition_cover' => [
                    'sometimes',
                    'nullable',
                    'string',
                    Rule::in(array_map(fn ($case) => $case->getDescription(), CoverStatusEnum::cases())),
                ],
            ];
        }

        // Add validation rules for adding a new record (addRecord method)
        if ($this->route() && $this->route()->getName() === 'records-import.add-record') {
            $rules = [
                'barcode' => 'nullable|string|distinct:strict',
                'cat_number' => 'nullable|string|distinct:strict',
                'title' => 'required|string',
                'release_id' => 'nullable|numeric|min:0', // Add release_id validation for add record
                'retail_price' => 'nullable|numeric|min:0',
                'wholesale_price' => 'nullable|numeric|min:0',
                'purchase_price' => 'nullable|numeric|min:0',
                'artist' => 'nullable|string',
                'format' => 'nullable|string',
                'label' => 'nullable|string',
                'description' => 'nullable|string',
                'comments' => 'nullable|string',
                'record_id' => [
                    'nullable',
                    'numeric',
                    function ($attribute, $value, $fail) {
                        // Only validate existence if record_id is not null and not 0 (0 means new record)
                        if ($value !== null && (int) $value !== 0 && ! Record::where('id', $value)->exists()) {
                            $fail(__('validation.exists', ['attribute' => $attribute]));
                        }
                    },
                ],
                'condition_disk' => [
                    'nullable',
                    'string',
                    Rule::in(array_map(fn ($case) => $case->getDescription(), DiskStatusEnum::cases())),
                ],
                'condition_cover' => [
                    'nullable',
                    'string',
                    Rule::in(array_map(fn ($case) => $case->getDescription(), CoverStatusEnum::cases())),
                ],
            ];
        }

        $this->logDetail('FINAL validation rules', [
            'action' => $action,
            'artist_rule' => $rules['records.*.artist'] ?? 'NOT SET',
            'format_rule' => $rules['records.*.format'] ?? 'NOT SET',
            'label_rule' => $rules['records.*.label'] ?? 'NOT SET',
            'title_rule' => $rules['records.*.title'] ?? 'NOT SET',
        ]);

        return $rules;
    }
}
