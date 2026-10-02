<?php

namespace App\Imports;

use App\Models\Record;
use App\Traits\Helpers;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class RecordsImport implements ToArray, WithCalculatedFormulas, WithMultipleSheets
{
    use Helpers, Importable;

    private array $required = ['ID', 'cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q'];

    private array $optionalColumns = [
        'price',
        'whole_price',
        'retail_price',
        'discount',
        'iva',
        'supplier',
        'condition_disk',
        'condition_cover',
        'comments',
        'description',
        'soft_delete',
        'delete',
        'd_delete',
        'release_id (discogs)', // Add release_id (discogs) to optional columns
    ];

    private array $processedData = [];

    private array $recordsToDelete = [];

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
        $instance = new static;

        return array_merge($instance->required, $instance->optionalColumns);
    }

    public function array(array $rows): array
    {
        // Check if file is empty
        if (empty($rows)) {
            throw new \Exception('Il file è vuoto');
        }

        // Get and validate headers first
        $headers = array_map('trim', array_map('strtolower', array_values($rows[0]))); // Normalize headers to lowercase

        // Check for missing required columns - normalize required columns to lowercase for comparison
        $requiredLowercase = array_map('strtolower', $this->required);
        $missing = array_diff($requiredLowercase, $headers);
        if (! empty($missing)) {
            // Map back to original case for error message
            $missingOriginal = [];
            foreach ($missing as $missingCol) {
                $key = array_search($missingCol, $requiredLowercase);
                $missingOriginal[] = $this->required[$key];
            }
            throw new \Exception('Colonne mancanti: '.implode(', ', $missingOriginal));
        }

        // Remove headers and filter out empty rows
        $dataRows = array_filter(
            array_slice($rows, 1),
            fn ($row) => ! empty(array_filter($row, fn ($cell) => $cell !== null && $cell !== ''))
        );

        // Check if we have any valid data rows
        if (empty($dataRows)) {
            throw new \Exception('Il file non contiene dati');
        }

        $areaHeaderMap = $this->getAreaColumnsFromHeaders($headers);

        $processedData = [];

        foreach ($dataRows as $row) {
            // Combine headers with row data
            $combined = array_combine($headers, $row);

            $record_id = (int) ($combined['id'] ?? 0); // Use lowercase since headers are normalized
            $barcode = self::cleanBarcode($combined['barcode'] ?? '');
            $cat_number = trim($combined['cat#'] ?? '');
            $quantity = (int) ($combined['q'] ?? 0); // Ensure quantity is integer

            $stocks = [];

            foreach ($areaHeaderMap as $colIndex => $areaId) {
                $qty = isset($row[$colIndex]) ? (int) $row[$colIndex] : null;

                if ($qty === null) {
                    continue;
                }

                $areaName = null;
                foreach ($this->getAreaColumnsFromHeaders($headers) as $idx => $aid) {
                    if ($idx === $colIndex) {
                        $areaName = $headers[$colIndex] ?? null;
                        break;
                    }
                }

                $stocks[] = [
                    'area_id' => $areaId,
                    'area_name' => $areaName,
                    'quantity' => $qty,
                ];
            }

            if (! empty($stocks)) {
                $combined['stocks_tmp'] = json_encode($stocks);
            } else {
                $combined['stocks_tmp'] = null;
            }

            // Not a double in the current file, process as new or existing DB record

            // Try to find existing record in DB
            // NOTE: record matching derived from the old application (bulk_ins.php, Item::GetByData)
            $dbRecord = null;
            if (! empty($record_id)) {
                $dbRecord = Record::with(['artist', 'format', 'label'])->find($record_id);
            }
            if (! $dbRecord && ! empty($barcode)) {
                $dbRecord = Record::with(['artist', 'format', 'label'])
                    ->where('barcode', $barcode)
                    ->first();
            }
            if (! $dbRecord && ! empty($cat_number)) {
                $dbRecord = Record::with(['artist', 'format', 'label'])
                    ->where('cat_number', $cat_number)
                    ->first();
            }

            $exist = ($dbRecord !== null);

            // if ($exist) {

            //     $deleteActions = [];
            //     if (isset($combined['delete']) && $combined['delete']) {
            //         $deleteActions[] = 'delete';
            //     }
            //     if (isset($combined['d_delete']) && $combined['d_delete']) {
            //         $deleteActions[] = 'd_delete';
            //     }
            //     if (isset($combined['soft_delete']) && $combined['soft_delete']) {
            //         $deleteActions[] = 'soft_delete';
            //     }

            //     if (! empty($deleteActions)) {
            //         $this->recordsToDelete[] = [
            //             'record' => $dbRecord,
            //             'actions' => $deleteActions,
            //             'identifier' => $dbRecord->barcode ?: $dbRecord->cat_number,
            //         ];
            //     }

            // } else {
            //     if (
            //         (isset($combined['d_delete']) && $combined['d_delete']) ||
            //         (isset($combined['soft_delete']) && $combined['soft_delete']) ||
            //         (isset($combined['delete']) && $combined['delete'])
            //     ) {
            //         continue;
            //     }
            // }

            // Explicitly cast price to float after reading, handle potential commas/symbols if necessary
            // Assuming price is numeric or null/empty. Add more robust cleaning if needed.
            $unit_price_raw = $combined['price'] ?? 0;
            $unit_price = (float) str_replace(',', '.', (string) $unit_price_raw); // Convert to string, replace comma, cast to float

            $discount_raw = $combined['discount'] ?? 0;
            $discount = (int) $discount_raw; // Ensure discount is integer

            $vat_raw = $combined['iva'] ?? 22;
            $vat = (int) $vat_raw; // Ensure vat is integer

            // Calculate total price using the float unit_price
            $total_price = round(($quantity * $unit_price) * (1 - ($discount / 100)), 2); // Already returns float

            // Get optional prices from spreadsheet
            $wholesale_price_raw = $combined['whole_price'] ?? null;
            $wholesale_price = $wholesale_price_raw !== null ? (float) str_replace(',', '.', (string) $wholesale_price_raw) : null;

            $retail_price_raw = $combined['retail_price'] ?? null;
            $retail_price = $retail_price_raw !== null ? (float) str_replace(',', '.', (string) $retail_price_raw) : null;

            // Prepare base record data from spreadsheet
            $record = [
                'cat_number' => $cat_number,
                // Cast text fields to string: a fully-numeric cell (e.g. a title like "2024")
                // is read by PhpSpreadsheet as int/float and would fail the `string` validation rule.
                'artist' => isset($combined['artist']) ? (string) $combined['artist'] : null,
                'title' => isset($combined['title']) ? (string) $combined['title'] : null,
                'release_id' => $combined['release_id (discogs)'] ?? null, // Add release_id handling from "release_id (discogs)" column
                'format' => isset($combined['fmt']) ? (string) $combined['fmt'] : null,
                'label' => isset($combined['label']) ? (string) $combined['label'] : null,
                'barcode' => $barcode,
                // 'quantity' => $quantity, // Already cast to int
                'purchase_price' => $unit_price, // Use the float value (this is the unit price from the import)
                'record_id' => $record_id, // Store the record_id from the import file
                'wholesale_price' => $wholesale_price, // Use the float/null value from spreadsheet initially
                'retail_price' => $retail_price, // Use the float/null value from spreadsheet initially
                // 'discount' => $discount, // Use the int value
                'vat' => $vat, // Use the int value
                'exist' => $exist,
                // Ensure these are the float values
                'unit_price' => $unit_price,
                'total_price' => $total_price,
                'supplier' => $combined['supplier'] ?? null,
                'condition_disk' => $combined['condition_disk'] ?? null,
                'condition_cover' => $combined['condition_cover'] ?? null,
                'comments' => $combined['comments'] ?? null,
                'description' => $combined['description'] ?? null,
                'soft_delete' => isset($combined['soft_delete']) ? (bool) $combined['soft_delete'] : false,
                'delete' => isset($combined['delete']) ? (bool) $combined['delete'] : false,
                'd_delete' => isset($combined['d_delete']) ? (bool) $combined['d_delete'] : false,
                'stocks_tmp' => $combined['stocks_tmp'] ?? null,
            ];

            // If record exists in DB, override/fallback fields
            if ($dbRecord) {
                // This will be used as a fallback for data that might be missing in the spreadsheet
                $record['parent_record'] = $dbRecord;

                // If no record_id was provided in the import file, use the found record's ID
                if (empty($record_id)) {
                    $record['record_id'] = $dbRecord->id;
                }
            }

            $processedData[] = $record;
        }

        $this->processedData = $processedData;

        // The return value of this method isn't directly used later,
        // but we return the original rows for potential compatibility.
        return $rows;
    }

    public function getProcessedData(): array
    {
        return $this->processedData;
    }

    // public function getRecordsToDelete(): array
    // {
    //     return $this->recordsToDelete;
    // }

    private function getAreaColumnsFromHeaders(array $headers): array
    {
        // SECURITY: Filter locations by admin roles to prevent unauthorized area access during import
        $areaColumns = \App\Models\Location::filterByAdminRoles()->with(['areas' => function ($q) {
            $q->orderBy('name');
        }])->get()->flatMap(function ($location) {
            $areas = $location->areas->sortByDesc(fn ($area) => $area->id === $location->default_area_id ? 1 : 0);

            return $areas->map(fn ($area) => [
                'location' => $location,
                'area' => $area,
                'header' => $location->name.' - '.$area->name.($area->id === $location->default_area_id ? ' (default)' : ''),
            ]);
        })->values()->all();

        $headerMap = [];
        foreach ($areaColumns as $col) {
            $headerLower = strtolower($col['header']);
            foreach ($headers as $i => $header) {
                if (strtolower($header) === $headerLower) {
                    $headerMap[$i] = $col['area']->id;
                }
            }
        }

        return $headerMap; // [colIndex => area_id]
    }
}
