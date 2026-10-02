<?php

namespace App\Imports;

use App\Models\Record;
use App\Models\Stock;
use App\Traits\Helpers;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class WholesaleOutImport implements ToArray, WithCalculatedFormulas, WithMultipleSheets
{
    use Helpers, Importable;

    // At least ONE of these identifier headers MUST be present in the XLSX file
    private array $identifierHeaders = ['cat#', 'barcode'];

    // This header MUST be present in the XLSX file
    private array $quantityHeader = ['order_amount']; // Array for consistency, though it's one item

    // All column names the importer can recognize and map from the XLSX header.
    // Keys are expected header names (lowercase), values are internal keys.
    private array $columnMap = [
        'cat#' => 'cat_number',
        'artist' => 'artist',
        'title' => 'title',
        'fmt' => 'format',
        'label' => 'label',
        'barcode' => 'barcode',
        'order_amount' => 'quantity', // This is the quantity to be sold
        'price' => 'unit_price',     // This is the selling price for this transaction
        'discount' => 'discount',
        'iva' => 'vat',
        // Note: area columns are handled dynamically (area_retail, area_warehouse, etc.)
    ];

    private array $processedData = [];

    private array $errors = ['errors' => [], 'warnings' => []];

    private array $notFoundRecords = [];

    private ?int $defaultAreaId;

    public function __construct(?int $defaultAreaId = null)
    {
        $this->defaultAreaId = $defaultAreaId;
    }

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
        // Area columns are added separately by WholesaleOutTemplateExport.
        $instance = new self;

        return array_keys($instance->columnMap);
    }

    public function array(array $rows): array
    {
        if (empty($rows)) {
            $this->errors['errors'][] = 'Il file è vuoto.';
            $this->processedData = [];

            return $rows;
        }

        // Get and validate headers first
        $headers = array_map('trim', array_map('strtolower', array_values($rows[0]))); // Normalize headers to lowercase

        // Check for missing required columns
        $foundIdentifierHeader = false;
        foreach ($this->identifierHeaders as $idHeader) {
            if (in_array($idHeader, $headers)) {
                $foundIdentifierHeader = true;
                break;
            }
        }

        $foundQuantityHeader = in_array($this->quantityHeader[0], $headers);

        if (! $foundIdentifierHeader || ! $foundQuantityHeader) {
            $missingParts = [];
            if (! $foundIdentifierHeader) {
                $missingParts[] = implode(' o ', $this->identifierHeaders);
            }
            if (! $foundQuantityHeader) {
                $missingParts[] = $this->quantityHeader[0];
            }
            $this->errors['errors'][] = 'Header del file non valido. Campi richiesti: '.implode(' e ', $missingParts).'.';
            $this->processedData = [];

            return $rows;
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
            $this->errors['errors'][] = 'Il file non contiene dati';
            $this->processedData = [];

            return $rows;
        }

        $processedData = [];
        $lookupMap = []; // Used to prevent doubles in the same file

        // Create a mapping to track original row numbers
        $originalRowNumbers = [];
        $filteredIndex = 0;
        foreach ($dataRows as $originalIndex => $row) {
            $originalRowNumbers[$filteredIndex] = $originalIndex + 2; // +2 because Excel is 1-indexed and we removed header
            $filteredIndex++;
        }

        // Reindex the array to have sequential keys
        $dataRows = array_values($dataRows);

        foreach ($dataRows as $rowIndex => $row) {
            $originalRowNumber = $originalRowNumbers[$rowIndex];

            // Combine headers with row data
            $combined = array_combine($headers, $row);

            $barcode = self::cleanBarcode($combined['barcode'] ?? '');
            $cat_number = trim($combined['cat#'] ?? '');
            $quantity = (int) ($combined['order_amount'] ?? 0); // Main quantity

            // Parse area-specific "x" marks to determine target area
            $areaQuantities = $this->parseAreaMarks($combined, $areaColumns);

            // Calculate remaining quantity that should go to default area (if any)
            $totalAreaQuantity = array_sum(array_column($areaQuantities, 'quantity'));
            $remainingQuantity = $quantity - $totalAreaQuantity;

            // The main quantity (order_amount) is the total quantity, like 'q' in WholesaleIn
            // Area quantities specify distribution, not additional quantity
            $effectiveQuantity = $quantity; // Always use order_amount as the total

            // Skip rows with invalid order_amount (<=0) without warning
            if ($effectiveQuantity <= 0) {
                // $this->errors['warnings'][] = 'Riga '.$originalRowNumber.": Quantità order_amount non valida per {$barcode}/{$cat_number}, riga saltata.";
                continue;
            }

            if (empty($barcode) && empty($cat_number)) {
                $this->errors['warnings'][] = 'Riga '.$originalRowNumber.': Barcode e Cat# mancanti, riga saltata.';

                continue;
            }

            // Check for duplicates in current file
            $foundIndex = null;
            if ($barcode !== '' && isset($lookupMap['barcode_'.$barcode])) {
                $foundIndex = $lookupMap['barcode_'.$barcode];
            } elseif ($cat_number !== '' && isset($lookupMap['cat#_'.$cat_number])) {
                $foundIndex = $lookupMap['cat#_'.$cat_number];
            }

            if ($foundIndex !== null) {
                // Found existing row, update quantity and merge area quantities
                $processedData[$foundIndex]['quantity'] += $effectiveQuantity;

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

                // Recalculate remaining quantity after merging
                $mergedTotalAreaQuantity = array_sum(array_column($mergedAreaQuantities, 'quantity'));
                $calculatedRemainingQuantity = $processedData[$foundIndex]['quantity'] - $mergedTotalAreaQuantity;
                $processedData[$foundIndex]['remaining_quantity'] = $calculatedRemainingQuantity;

                // If there's remaining quantity and we have a default area, include it in area_quantities
                if ($calculatedRemainingQuantity > 0 && $this->defaultAreaId) {
                    $defaultArea = \App\Models\Area::find($this->defaultAreaId);
                    if ($defaultArea) {
                        // Check if default area is already in merged quantities
                        $defaultAreaExists = false;
                        foreach ($mergedAreaQuantities as $key => $areaQty) {
                            if ($areaQty['area_id'] == $this->defaultAreaId) {
                                $mergedAreaQuantities[$key]['quantity'] += $calculatedRemainingQuantity;
                                $defaultAreaExists = true;
                                break;
                            }
                        }

                        if (! $defaultAreaExists) {
                            $mergedAreaQuantities[] = [
                                'area_id' => $this->defaultAreaId,
                                'area_name' => $defaultArea->name,
                                'quantity' => $calculatedRemainingQuantity,
                            ];
                        }

                        $processedData[$foundIndex]['area_quantities'] = array_values($mergedAreaQuantities);
                        $processedData[$foundIndex]['remaining_quantity'] = 0; // No remaining since it's assigned to default area
                    }
                }

                // Recalculate total price for the updated quantity
                $existingRecord = $processedData[$foundIndex];
                $processedData[$foundIndex]['total_price'] = round(($existingRecord['quantity'] * $existingRecord['unit_price']) * (1 - ($existingRecord['discount'] / 100)), 2);

                // Ensure available_quantity is present (should already be there, but just in case)
                if (! isset($processedData[$foundIndex]['available_quantity'])) {
                    // Get the DB record for this stock_id to calculate available quantity
                    $stockId = $processedData[$foundIndex]['stock_id'];
                    if ($stockId) {
                        $stock = Stock::find($stockId);
                        if ($stock) {
                            $dbRecord = Record::find($stock->record_id);
                            if ($dbRecord) {
                                $processedData[$foundIndex]['available_quantity'] = $dbRecord->stocks()->sum('quantity');
                            }
                        }
                    } else {
                        // For backorder scenarios (stock_id is null), use record_id to get total available quantity
                        $recordId = $processedData[$foundIndex]['record_id'];
                        if ($recordId) {
                            $dbRecord = Record::find($recordId);
                            if ($dbRecord) {
                                $processedData[$foundIndex]['available_quantity'] = $dbRecord->stocks()->sum('quantity');
                            }
                        }
                    }
                }

            } else {
                // Not a duplicate, process as new record

                // NOTE: record matching derived from the old application (whole_out.php, Item::GetByData)
                $dbRecord = null;

                if (! empty($barcode)) {
                    $dbRecord = Record::with(['artist', 'format', 'label'])->where('barcode', $barcode)->first();
                }
                if (! $dbRecord && ! empty($cat_number)) {
                    $dbRecord = Record::with(['artist', 'format', 'label'])->where('cat_number', $cat_number)->first();
                }

                if (! $dbRecord) {
                    $warningMessage = 'Riga '.($rowIndex + 2).': Record non trovato per barcode "'.$barcode.'" o cat# "'.$cat_number.'", riga saltata.';
                    // $this->errors['warnings'][] = $warningMessage; // Commented out - warnings now handled by dedicated not-found display

                    // Store structured not-found record data for search functionality
                    $this->notFoundRecords[] = [
                        'row_number' => $originalRowNumber,
                        'cat_number' => $cat_number,
                        'barcode' => $barcode,
                        'artist' => $combined['artist'] ?? '',
                        'title' => $combined['title'] ?? '',
                        'format' => $combined['fmt'] ?? '',
                        'label' => $combined['label'] ?? '',
                        'quantity' => $effectiveQuantity,
                        'price' => $combined['price'] ?? '',
                    ];

                    continue;
                }

                // For WholesaleOut, we need to find the best stock based on areas or fallback logic
                $stockInfo = $this->determineStockForRecord($dbRecord, $areaQuantities, $combined, $originalRowNumber);

                if ($stockInfo === null) {
                    continue; // Error already logged in determineStockForRecord
                }

                $stock = $stockInfo['stock']; // Can be null for backorder scenarios
                $areaName = $stockInfo['area_name'];

                // Handle backorder scenario (when stock is null but we have area info)
                $recordId = $stock ? $stock->record_id : ($stockInfo['record_id'] ?? $dbRecord->id);
                $stockId = $stock ? $stock->id : null; // null for backorders

                // Get pricing information
                $unitPrice = isset($combined['price']) && is_numeric($combined['price'])
                    ? (float) $combined['price']
                    : ($dbRecord->wholesale_price ? (float) $dbRecord->wholesale_price->getAmount() / 100 : 0.0); // Convert Money object to float safely
                $discount = isset($combined['discount']) && is_numeric($combined['discount']) ? (float) $combined['discount'] : 0;
                $vat = isset($combined['iva']) && is_numeric($combined['iva']) ? (float) $combined['iva'] : 22;

                $totalPrice = ($unitPrice * $effectiveQuantity) * (1 - ($discount / 100));

                // Add to lookup map to prevent future duplicates
                if ($barcode !== '') {
                    $lookupMap['barcode_'.$barcode] = count($processedData);
                }
                if ($cat_number !== '') {
                    $lookupMap['cat#_'.$cat_number] = count($processedData);
                }

                // Calculate total available quantity across all areas for this record
                $totalAvailableQuantity = $dbRecord->stocks()->sum('quantity');

                // Single area mode: include area quantities if areas were marked
                $finalAreaQuantities = [];
                $calculatedRemainingQuantity = 0;

                if (! empty($areaQuantities)) {
                    // User marked a specific area with "x" - use that area with full quantity
                    $markedArea = $areaQuantities[0];
                    $finalAreaQuantities = [
                        [
                            'area_id' => $markedArea['area_id'],
                            'area_name' => $markedArea['area_name'],
                            'quantity' => $effectiveQuantity, // Use full quantity for the marked area
                        ],
                    ];
                    $calculatedRemainingQuantity = 0; // No remaining since all goes to marked area
                } else {
                    // No area marked - use default area for UI preview
                    $finalAreaQuantities = [];
                    if ($this->defaultAreaId) {
                        $defaultArea = \App\Models\Area::find($this->defaultAreaId);
                        if ($defaultArea) {
                            $finalAreaQuantities = [
                                [
                                    'area_id' => $this->defaultAreaId,
                                    'area_name' => $defaultArea->name,
                                    'quantity' => $effectiveQuantity, // Use full quantity for default area
                                ],
                            ];
                        }
                    }
                    $calculatedRemainingQuantity = 0;
                }

                $processedData[] = [
                    'stock_id' => $stockId, // Can be null for backorder scenarios
                    'record_id' => $recordId,
                    'quantity' => $effectiveQuantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'total_price' => round($totalPrice, 2),
                    'vat' => $vat,
                    'area_quantities' => $finalAreaQuantities, // Include area quantities with default area
                    'remaining_quantity' => $calculatedRemainingQuantity, // For UI preview purposes
                    // Display data from DB record for consistency
                    'cat_number' => $dbRecord->cat_number,
                    'barcode' => $dbRecord->barcode,
                    'artist_name' => $dbRecord->artist->name ?? null,
                    'title' => $dbRecord->title,
                    'format_name' => $dbRecord->format->name ?? null,
                    'label_name' => $dbRecord->label->name ?? null,
                    'available_quantity' => $totalAvailableQuantity, // Total stock available across all areas
                    'stock' => $dbRecord->stocks->map(function ($stock) {
                        return [
                            'id' => $stock->id,
                            'record_id' => $stock->record_id,
                            'area_id' => $stock->area_id,
                            'quantity' => $stock->quantity,
                        ];
                    })->toArray(),
                    // Keep original input for reference
                    '_original_cat_number' => $cat_number,
                    '_original_barcode' => $barcode,
                    '_original_artist' => $combined['artist'] ?? '',
                    '_original_title' => $combined['title'] ?? '',
                    '_original_area_name' => $areaName,
                ];
            }
        }

        $this->processedData = $processedData;

        return $this->processedData;
    }

    // getRecords is a more descriptive name for what the controller wants
    public function getRecords(): array
    {
        return $this->processedData;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getNotFoundRecords(): array
    {
        return $this->notFoundRecords;
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
     * Parse area-specific "x" marks from the row data (simplified single-area mode)
     */
    private function parseAreaMarks(array $combined, array $areaColumns): array
    {
        foreach ($areaColumns as $areaColumn) {
            $columnName = $areaColumn['column_name'];
            $value = trim(strtolower($combined[$columnName] ?? ''));

            // Look for "x" mark in the area column
            if ($value === 'x' || $value === '1') {
                // Return the area info to be used for stock determination
                return [
                    [
                        'area_id' => $areaColumn['area_id'],
                        'area_name' => $areaColumn['area_name'],
                        'quantity' => 0, // Not used in single area mode
                        'marked' => true, // Indicates this area was marked with "x"
                    ],
                ];
            }
        }

        // Return empty array if no area is marked
        return [];
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

    /**
     * Determine the best stock for a record based on area quantities or fallback logic
     *
     * Priority order:
     * 1. Use area quantities specified in XLSX (multiple areas mode)
     * 2. Use legacy area column specified in XLSX
     * 3. Use WholesaleOut's default area (always available since area_id is required)
     *
     * Note: Since area_id is required in validation, the default area should always exist.
     * The guessing logic has been removed as it's no longer needed.
     */
    private function determineStockForRecord(Record $dbRecord, array $areaQuantities, array $combined, int $rowNumber): ?array
    {
        $areaName = $combined['area'] ?? null; // Legacy area column support

        // Handle single area mode - use specified area or marked area
        if (! empty($areaQuantities)) {
            $firstArea = $areaQuantities[0];

            if (isset($firstArea['marked'])) {
                // Use the specified area (either from quantities or from "x" mark)
                $firstAreaId = $firstArea['area_id'];
                $stock = Stock::where('record_id', $dbRecord->id)
                    ->where('area_id', $firstAreaId)
                    ->first();

                $area = \App\Models\Area::find($firstAreaId);
                $areaName = $area ? $area->name : 'Unknown Area';

                if ($stock) {
                    return [
                        'stock' => $stock,
                        'area_name' => $areaName,
                    ];
                } else {
                    // No stock found in the specified area - allow backorder creation
                    $this->errors['warnings'][] = "Riga {$rowNumber}: Stock non trovato per {$dbRecord->cat_number} / {$dbRecord->barcode} nella sede {$areaName}. Verrà creato un backorder.";

                    return [
                        'stock' => null, // This will be handled as backorder scenario
                        'area_name' => $areaName,
                        'record_id' => $dbRecord->id, // Include record_id for backorder creation
                        'area_id' => $firstAreaId, // Include area_id for area tracking
                    ];
                }
            }
        }

        // Fallback to legacy area column logic
        if (! empty($areaName)) {
            $stock = Stock::where('record_id', $dbRecord->id)
                ->whereHas('area', function ($query) use ($areaName) {
                    $query->where('name', $areaName);
                })
                ->first();

            if (! $stock) {
                $this->errors['errors'][] = "Riga {$rowNumber}: Stock non trovato per {$dbRecord->cat_number} / {$dbRecord->barcode} presso la sede specificata '{$areaName}'.";

                return null;
            }

            return [
                'stock' => $stock,
                'area_name' => $areaName,
            ];
        }

        // No area specified, use the WholesaleOut's default area
        if (! $this->defaultAreaId) {
            // This should never happen since area_id is required in validation, but throw error if it does
            $this->errors['errors'][] = 'Errore di sistema: Area di default non specificata per il WholesaleOut.';

            return null;
        }

        $stock = Stock::where('record_id', $dbRecord->id)
            ->where('area_id', $this->defaultAreaId)
            ->first();

        $defaultArea = \App\Models\Area::find($this->defaultAreaId);
        $areaName = $defaultArea ? $defaultArea->name : 'Default Area';

        if ($stock) {
            return [
                'stock' => $stock,
                'area_name' => $areaName,
            ];
        } else {
            // No stock found in the default area - allow backorder creation
            // $this->errors['warnings'][] = "Riga {$rowNumber}: Stock non trovato per {$dbRecord->cat_number} / {$dbRecord->barcode} nella sede di default {$areaName}. Verrà creato un backorder.";

            return [
                'stock' => null, // This will be handled as backorder scenario
                'area_name' => $areaName,
                'record_id' => $dbRecord->id, // Include record_id for backorder creation
                'area_id' => $this->defaultAreaId, // Include area_id for area tracking
            ];
        }
    }
}
