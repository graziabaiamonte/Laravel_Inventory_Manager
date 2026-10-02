<?php

namespace App\Http\Requests;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Enums\ForSaleOnDiscogsStatusEnum;
use App\Enums\RecordTypeEnum;
use App\Models\Stock;
use App\Rules\AlphanumericBarcode;
use App\Traits\Helpers;
use Illuminate\Validation\Rules\Enum;

class RecordRequest extends BaseRequest
{
    use Helpers;

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('barcode')) {
            $this->merge([
                'barcode' => self::cleanBarcode($this->input('barcode')),
            ]);
        }
    }

    /**
     * Custom validation messages
     */
    public function messages(): array
    {
        return [
            // The edit form resubmits every existing stock row, so a record
            // holding a negative quantity from before hits min:0 on save. The
            // default message reads "stock.2.quantity deve essere minimo 0",
            // which names an array index instead of saying what is wrong.
            'stock.*.quantity.min' => 'Non è possibile salvare uno stock negativo.',

            // A barcode typed or scanned into a quantity field lands here.
            'stock.*.quantity.max' => 'Quantità non valida: il massimo consentito è '
                .number_format(Stock::MAX_QUANTITY, 0, ',', '.').'.',
        ];
    }

    public function authorizeAction($action): bool
    {
        $user = $this->user();

        return match ($action) {
            'index', 'store' => true,
            'update', 'destroy' => $user->can('update', $this->route('record')),
            default => false
        };
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function validateAction($action): array
    {
        switch ($action) {
            case 'store':
            case 'update':
                return [
                    'barcode' => ['nullable', new AlphanumericBarcode],
                    'cat_number' => ['nullable'],
                    'release_id' => ['nullable', 'numeric'],
                    'type' => ['required', 'string', new Enum(RecordTypeEnum::class)],
                    'title' => ['required', 'string'],
                    'retail_price' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
                    'wholesale_price' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
                    'purchase_price' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
                    'disk_status' => ['required', 'integer', new Enum(DiskStatusEnum::class)],
                    'cover_status' => ['required', 'integer', new Enum(CoverStatusEnum::class)],
                    'for_sale_on_discogs' => ['required', 'integer',  new Enum(ForSaleOnDiscogsStatusEnum::class), function ($attribute, $value, $fail) {
                        // Only validate if for_sale_on_discogs is being set to 1 (ForSale)
                        if ($value == 1) {
                            $currentStock = 0;

                            if ($this->route('record')) {
                                // For existing records, get current stock
                                $record = $this->route('record');
                                $currentStock = $record->total_stocks;
                            }

                            // Check if new stocks are being submitted in this request
                            $requestStocks = $this->input('stock', []);
                            $newStockTotal = collect($requestStocks)->sum('quantity');

                            // Combined total: existing stock + new stock from this request
                            $totalStock = $currentStock + $newStockTotal;

                            if ($totalStock <= 0) {
                                if ($this->route('record')) {
                                    $fail('Cannot set record for sale on Discogs when total stock is 0 or less.');
                                } else {
                                    $fail('Cannot set record for sale on Discogs when creating a new record without stock.');
                                }
                            }
                        }
                    }],
                    'discogs_id' => ['nullable', 'integer'],
                    'description' => ['nullable'],
                    'comments' => ['nullable'],
                    'location_text' => ['nullable', 'string'],
                    'label_id' => [
                        'nullable',
                        'integer',
                        function ($attribute, $value, $fail) {
                            // Allow 0 for "create new" pattern, null for empty, or must exist in database
                            if ($value !== 0 && $value !== null && ! \App\Models\Label::where('id', $value)->exists()) {
                                $fail("L'elemento label id selezionato non è valido.");
                            }
                        },
                    ],
                    'label_name' => ['nullable', 'string', 'required_if:label_id,0'],
                    'format_id' => ['required', 'exists:formats,id'],
                    'artist_id' => [
                        'nullable',
                        'integer',
                        function ($attribute, $value, $fail) {
                            // Allow 0 for "create new" pattern, null for empty, or must exist in database
                            if ($value !== 0 && $value !== null && ! \App\Models\Artist::where('id', $value)->exists()) {
                                $fail("L'elemento artist id selezionato non è valido.");
                            }
                        },
                    ],
                    'artist_name' => ['nullable', 'string', 'required_if:artist_id,0'],
                    'media_upload' => [
                        'nullable',
                        'array',
                        function ($attribute, $value, $fail) {
                            $allowedMimeTypes = [
                                'image/jpeg', 'image/png', 'image/gif',
                            ];

                            // Verifica ogni file nell'array
                            foreach ($value as $file) {
                                if (! $file || ! method_exists($file, 'getMimeType')) {
                                    $fail($attribute.' contiene un file non valido.');

                                    return;
                                }

                                if (! in_array($file->getMimeType(), $allowedMimeTypes)) {
                                    $fail($attribute.' contiene un file in formato non supportato.');

                                    return;
                                }
                            }
                        },
                        'max:4096',
                    ],
                    'media' => ['array', 'nullable'],
                    'discogs_image_url' => ['url', 'nullable'],
                    'stock' => ['array', 'nullable'],
                    'stock.*.area_id' => [
                        'required_with:stock',
                        'integer',
                        'exists:areas,id',
                        // Note: Area access validation moved to controller
                        // to check only when stock actually changes
                    ],
                    'stock.*.quantity' => ['required_with:stock', 'integer', 'min:0', 'max:'.Stock::MAX_QUANTITY],
                    'stock.*.description' => ['nullable', 'string'],
                ];

            default:
                return [];
        }
    }
}
