<?php

namespace App\Imports;

use App\Models\Record;
use App\Traits\CurrencyHelper;
use App\Traits\Helpers;
use App\Traits\LogsToChannel;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class WholesaleInImport implements ToArray, WithCalculatedFormulas, WithMultipleSheets
{
    use CurrencyHelper, Helpers, Importable;
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'wholesale_in';
    }

    private array $required = ['cat#', 'title', 'barcode', 'q'];

    private array $optional = [
        'artist',
        'fmt',
        'label',
        'price',
        'whole_price',
        'retail_price',
        'discount',
        'iva',
        'condition_disk',
        'condition_cover',
        'for_sale_on_discogs',
        'release_id (discogs)',
        'discogs_id',
    ];

    private array $processedData = [];

    /**
     * Only process the first sheet (index 0), ignoring any additional sheets.
     */
    public function sheets(): array
    {
        return [0 => $this];
    }

    public static function getBaseHeaders(): array
    {
        // Headers for the import template.
        // These should align with what the import process expects.
        // Location columns are added separately by WholesaleInTemplateExport.
        // $instance = new static;

        // return array_merge($instance->required, $instance->optional);

        $orderedHeaders = [
            'cat#',      // required
            'artist',    // optional
            'title',     // required
            'fmt',       // optional
            'label',     // optional
            'barcode',   // required
            'q',         // required
            'price',
            'whole_price',
            'retail_price',
            'discount',
            'iva',
            'condition_disk',
            'condition_cover',
            'for_sale_on_discogs',
            'release_id (discogs)',
            'discogs_id',
        ];

        return $orderedHeaders;
    }

    public function array(array $rows): array
    {
        // Check if file is empty
        if (empty($rows)) {
            throw new \Exception('Il file è vuoto');
        }

        // Get and validate headers first
        $headers = array_map('trim', array_map('strtolower', array_values($rows[0]))); // Normalize headers to lowercase

        // Check for missing required columns
        $missing = array_diff($this->required, $headers);
        if (! empty($missing)) {
            throw new \Exception('Colonne mancanti: '.implode(', ', $missing));
        }

        // Identify area columns by getting normalized area names from database
        $areaColumns = $this->getAreaColumnMapping($headers);

        // Remove headers and filter out empty rows
        $dataRows = array_filter(
            array_slice($rows, 1),
            fn ($row) => ! empty(array_filter($row, fn ($cell) => $cell !== null && $cell !== ''))
        );

        // Check if we have any valid data rows
        if (empty($dataRows)) {
            throw new \Exception('Il file non contiene dati');
        }

        $processedData = [];
        $lookupMap = []; // Used to prevent doubles in the same file. Stores index in $processedData based on barcode or cat#

        foreach ($dataRows as $row) {
            // Combine headers with row data
            $combined = array_combine($headers, $row);

            $barcode = self::cleanBarcode($combined['barcode'] ?? '');
            $cat_number = trim($combined['cat#'] ?? '');
            $quantity = (int) ($combined['q'] ?? 0); // Ensure quantity is integer

            // Parse area-specific quantities
            $areaQuantities = $this->parseAreaQuantities($combined, $areaColumns);

            // Validate: 'q' column must have a value > 0 when area quantities are specified
            // This ensures the total quantity matches the distribution logic
            if ($quantity <= 0 && ! empty($areaQuantities)) {
                throw new \Exception(
                    "La colonna 'q' deve avere un valore > 0 quando sono specificate quantità per area. ".
                    "Record: barcode='{$barcode}', cat#='{$cat_number}'"
                );
            }

            // Debug logging
            if (! empty($areaQuantities)) {
                $this->logInfo('Parsed area quantities', [
                    'cat_number' => $cat_number,
                    'barcode' => $barcode,
                    'main_quantity' => $quantity,
                    'area_quantities' => $areaQuantities,
                ]);
            }

            // Skip rows with no quantity data (both q and areas empty)
            // This allows incomplete rows to be silently ignored without blocking the import
            if ($quantity <= 0 && empty($areaQuantities)) {
                continue;
            }

            // Always use the main quantity from 'q' column
            // Area quantities are handled separately and should not affect the main quantity
            $finalQuantity = $quantity;

            $foundIndex = null;

            // Check if the current row's barcode or cat_number already exists in the lookup map (the current file)
            if ($barcode !== '' && isset($lookupMap['barcode_'.$barcode])) {
                // Prioritize barcode for lookup if present
                $foundIndex = $lookupMap['barcode_'.$barcode];
            } elseif ($cat_number !== '' && isset($lookupMap['cat#_'.$cat_number])) {
                // Fallback to cat_number if barcode didn't match or wasn't present
                $foundIndex = $lookupMap['cat#_'.$cat_number];
            }

            if ($foundIndex !== null) {
                // Found existing row, update his quantity and merge area quantities
                $processedData[$foundIndex]['quantity'] += $finalQuantity;

                // Merge area quantities
                $existingAreaQuantities = $processedData[$foundIndex]['area_quantities'] ?? [];
                $mergedAreaQuantities = [];

                // Start with existing area quantities
                foreach ($existingAreaQuantities as $existingArea) {
                    $mergedAreaQuantities[$existingArea['area_id']] = $existingArea;
                }

                // Add or update with new area quantities
                foreach ($areaQuantities as $newArea) {
                    if (isset($mergedAreaQuantities[$newArea['area_id']])) {
                        $mergedAreaQuantities[$newArea['area_id']]['quantity'] += $newArea['quantity'];
                    } else {
                        $mergedAreaQuantities[$newArea['area_id']] = $newArea;
                    }
                }

                $processedData[$foundIndex]['area_quantities'] = array_values($mergedAreaQuantities);

                // Recalculate total price for the updated quantity
                $existingRecord = $processedData[$foundIndex];
                $processedData[$foundIndex]['total_price'] = round(($existingRecord['quantity'] * $existingRecord['unit_price']) * (1 - ($existingRecord['discount'] / 100)), 2);

            } else {
                // Not a double in the current file, process as new or existing DB record

                // NOTE: record matching derived from the old application (whole_in.php, Item::GetByData)
                $dbRecord = null;
                if (! empty($barcode)) {
                    // Eager load relationships needed later
                    $dbRecord = Record::with(['artist', 'format', 'label'])->where('barcode', $barcode)->first();
                }
                if (! $dbRecord && ! empty($cat_number)) {
                    // Eager load relationships needed later
                    $dbRecord = Record::with(['artist', 'format', 'label'])->where('cat_number', $cat_number)->first();
                }

                // $exist = ($dbRecord !== null);

                // Explicitly cast price to float after reading, handle potential commas/symbols if necessary
                // Assuming price is numeric or null/empty. Add more robust cleaning if needed.
                $unit_price_raw = $combined['price'] ?? 0;
                $unit_price = (float) $this->cleanCurrencyValue($unit_price_raw);

                $discount_raw = $combined['discount'] ?? 0;
                $discount = (int) $discount_raw; // Ensure discount is integer

                $vat_raw = $combined['iva'] ?? 22;
                $vat = (int) $vat_raw; // Ensure vat is integer

                // Calculate total price using the float unit_price
                $total_price = round(($quantity * $unit_price) * (1 - ($discount / 100)), 2); // Already returns float

                // Get optional prices from spreadsheet
                $wholesale_price_raw = $combined['whole_price'] ?? null;
                $wholesale_price_from_sheet = $wholesale_price_raw !== null ?
                    (float) $this->cleanCurrencyValue($wholesale_price_raw) : null;

                $retail_price_raw = $combined['retail_price'] ?? null;
                $retail_price_from_sheet = $retail_price_raw !== null ?
                    (float) $this->cleanCurrencyValue($retail_price_raw) : null;

                // Handle Discogs integration fields
                $forSaleOnDiscogs = $combined['for_sale_on_discogs'] ?? 0;
                $discogsId = $combined['discogs_id'] ?? null;
                // Release ID identifies the disc and is what actually lets a record be published:
                // it is imported into records.release_id so a new record can be listed straight away.
                $releaseId = $combined['release_id (discogs)'] ?? null;

                // Debug logging for Discogs fields
                $this->logInfo('Processing Discogs integration fields', [
                    'cat_number' => $cat_number,
                    'barcode' => $barcode,
                    'for_sale_on_discogs_raw' => $combined['for_sale_on_discogs'] ?? 0,
                    'for_sale_on_discogs_processed' => $forSaleOnDiscogs,
                    'release_id' => $releaseId,
                    'discogs_id' => $discogsId,
                ]);

                // Prepare base record data from spreadsheet
                $record = [
                    'cat_number' => $cat_number,
                    // Cast text fields to string: a fully-numeric cell (e.g. a title like "2024")
                    // is read by PhpSpreadsheet as int/float and would fail the `string` validation rule.
                    'artist' => isset($combined['artist']) ? (string) $combined['artist'] : null,
                    'title' => isset($combined['title']) ? (string) $combined['title'] : null,
                    'format' => isset($combined['fmt']) ? (string) $combined['fmt'] : null,
                    'label' => isset($combined['label']) ? (string) $combined['label'] : null,
                    'barcode' => $barcode,
                    'quantity' => $finalQuantity, // Use finalQuantity instead of $quantity
                    'purchase_price' => $unit_price,
                    'record_id' => 0, // will be updated if record exists
                    'wholesale_price' => $dbRecord ? $dbRecord->wholesale_price->formatByDecimal() : $wholesale_price_from_sheet, // If dbRecord exists, set to wholesale price, otherwise use sheet value
                    'retail_price' => $dbRecord ? $dbRecord->retail_price->formatByDecimal() : $retail_price_from_sheet,    // If dbRecord exists, set to retail price, otherwise use sheet value
                    'discount' => $discount,
                    'vat' => $vat,
                    'unit_price' => $unit_price,
                    'total_price' => $total_price,
                    'area_quantities' => $areaQuantities, // Add area-specific quantities
                    'condition_disk' => $combined['condition_disk'] ?? null,
                    'condition_cover' => $combined['condition_cover'] ?? null,
                    'for_sale_on_discogs' => $forSaleOnDiscogs,
                    'release_id' => $releaseId,
                    'discogs_id' => $discogsId,
                ];

                // If record exists in DB, override/fallback fields
                if ($dbRecord) {
                    // This will be used as a fallback for data that might be missing in the spreadsheet
                    $record['parent_record'] = $dbRecord;

                    $record['record_id'] = $dbRecord->id;
                    $record['artist'] = $dbRecord->artist?->name;
                    $record['title'] = $dbRecord->title;
                    $record['format'] = $dbRecord->format?->name;
                    $record['label'] = $dbRecord->label?->name;

                    // wholesale_price and retail_price are already set to null above if $dbRecord exists.
                    // We do NOT want to fall back to $dbRecord->wholesale_price or $dbRecord->retail_price here.

                    // If unit_price from spreadsheet was 0 and dbRecord has a purchase_price, use it.
                    if (($record['unit_price'] == 0 || is_null($record['unit_price'])) && $dbRecord->purchase_price !== null) {
                        $new_unit_price = (float) $dbRecord->purchase_price->formatByDecimal();
                        $record['unit_price'] = $new_unit_price;
                        // Recalculate total_price with the new unit_price
                        $record['total_price'] = round(($record['quantity'] * $new_unit_price) * (1 - ($record['discount'] / 100)), 2);
                    }
                    // NOTE: purchase_price is always taken from the spreadsheet ('price' column -> unit_price)
                    // as it represents the cost for *this specific* wholesale import.
                }

                $processedData[] = $record;
                $newIndex = count($processedData) - 1;

                // Add to lookup map using barcode and/or cat_number if they exist
                // NOTE: $barcode has priority over $cat_number
                if ($barcode !== '') {
                    $lookupMap['barcode_'.$barcode] = $newIndex;
                }
                if ($cat_number !== '') {
                    if (! isset($lookupMap['cat#_'.$cat_number])) {
                        $lookupMap['cat#_'.$cat_number] = $newIndex;
                    }
                }
            }
        }

        $this->processedData = $processedData;

        // The return value of this method isn't directly used later,
        // but we return the original rows for potential compatibility.
        return $rows;
    }

    /**
     * Map area column headers to area IDs by matching normalized names
     */
    private function getAreaColumnMapping(array $headers): array
    {
        $areaColumns = [];

        // SECURITY: Get only authorized active areas to prevent unauthorized access during import
        $areas = \App\Models\Area::filterByAdminRoles()->where('status', 1)->get();

        foreach ($areas as $area) {
            $normalizedAreaName = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($area->name));

            // Find matching header
            $headerIndex = array_search($normalizedAreaName, $headers);
            if ($headerIndex !== false) {
                $areaColumns[$headerIndex] = [
                    'area_id' => $area->id,
                    'area_name' => $area->name,
                    'column_name' => $normalizedAreaName,
                ];
            }
        }

        return $areaColumns;
    }

    /**
     * Parse area-specific quantities from the row data
     */
    private function parseAreaQuantities(array $combined, array $areaColumns): array
    {
        $areaQuantities = [];

        foreach ($areaColumns as $areaColumn) {
            $columnName = $areaColumn['column_name'];
            $quantity = (int) ($combined[$columnName] ?? 0);

            if ($quantity > 0) {
                $areaQuantities[] = [
                    'area_id' => $areaColumn['area_id'],
                    'area_name' => $areaColumn['area_name'],
                    'quantity' => $quantity,
                ];
            }
        }

        return $areaQuantities;
    }

    public function getProcessedData(): array
    {
        return $this->processedData;
    }
}
