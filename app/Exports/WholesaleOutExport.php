<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsBarcodeColumnAsText;
use App\Models\WholesaleOut;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WholesaleOutExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithStyles
{
    use FormatsBarcodeColumnAsText;

    protected WholesaleOut $wholesaleOut;

    public function __construct(WholesaleOut $wholesaleOut)
    {
        $this->wholesaleOut = $wholesaleOut;
    }

    public function array(): array
    {
        // Load the wholesale out with necessary relationships
        $this->wholesaleOut->load([
            'customer',
            'records.parentRecord.artist',
            'records.parentRecord.label',
            'records.parentRecord.format',
        ]);

        // Filter records to only include those with shipped_quantity > 0
        $shippedRecords = $this->wholesaleOut->records->filter(function ($record) {
            return $record->shipped_quantity > 0;
        });

        // Reverse of the UI order (which is position ASC, id ASC = most recently added first),
        // so the export lists records oldest-added first
        $records = $this->wholesaleOut->records()
            ->orderByDesc('position')
            ->orderByDesc('id')
            ->get();

        // Calculate total pieces from shipped records only
        $totalPieces = $shippedRecords->sum('shipped_quantity');

        // Build the array starting with header information (matching original format)
        $data = [
            // Header rows (6 rows)
            ['Documento', $this->wholesaleOut->doc_num],
            ['Cliente', $this->wholesaleOut->customer?->name ?? ''],
            ['Data', $this->wholesaleOut->created_at?->format('d/m/Y H:i:s') ?? ''],
            ['Pezzi', $totalPieces],
            ['Totale', $this->wholesaleOut->total_price ? $this->wholesaleOut->total_price->getAmount() / 100 : 0],
            [$this->wholesaleOut->description ?? ''],

            // Column headers for the data table (row 7) - matching original format
            [
                'cat#',
                'artist',
                'title',
                'fmt',
                'label',
                'barcode',
                'q',
                'shipped',
                'prezzo ingrosso',
                'discount',
                'total',
                'IVA',
            ],
        ];

        // Add the data rows (matching original format with uppercase text) - only shipped records
        foreach ($records as $wholesaleOutRecord) {
            $record = $wholesaleOutRecord->parentRecord;

            $data[] = [
                strtoupper($record?->cat_number ?? ''),
                strtoupper($record?->artist?->name ?? ''),
                strtoupper($record?->title ?? ''),
                strtoupper($record?->format?->name ?? ''),
                strtoupper($record?->label?->name ?? ''),
                $record?->barcode ?? '',
                $wholesaleOutRecord->quantity ?? 0,
                $wholesaleOutRecord->shipped_quantity ?? 0,
                $wholesaleOutRecord->unit_price ? $wholesaleOutRecord->unit_price->getAmount() / 100 : 0,
                $wholesaleOutRecord->discount ?? 0,
                $wholesaleOutRecord->total_price ? $wholesaleOutRecord->total_price->getAmount() / 100 : 0,
                $wholesaleOutRecord->vat ?? 0,
            ];
        }

        // Add summary section (matching original format) - only for shipped records
        $this->addSummarySection($data, $shippedRecords);

        return $data;
    }

    protected function addSummarySection(array &$data, $shippedRecords = null): void
    {
        // Use shipped records if provided, otherwise use all records (for backward compatibility)
        $recordsToSummarize = $shippedRecords ?? $this->wholesaleOut->records;

        // Group records by format, unit_price, and discount for summary (matching original SQL logic)
        $summary = $recordsToSummarize
            ->groupBy(function ($record) {
                return $record->parentRecord->format_id.'_'.
                       $record->unit_price->getAmount().'_'.
                       ($record->discount ?? 0);
            })
            ->map(function ($groupedRecords) {
                $firstRecord = $groupedRecords->first();
                $totalQuantity = $groupedRecords->sum('shipped_quantity');

                // Convert to float for calculations (matching original logic)
                $unitPrice = $firstRecord->unit_price->getAmount() / 100;
                $discount = $firstRecord->discount ?? 0;
                $discountedUnitPrice = $unitPrice;
                $rowTotal = $totalQuantity * $unitPrice;

                // Apply discount if present (matching original calculation)
                if ($discount > 0) {
                    $discountMultiplier = (100 - $discount) / 100;
                    $rowTotal *= $discountMultiplier;
                    $discountedUnitPrice *= $discountMultiplier;
                }

                return [
                    'quantity' => $totalQuantity,
                    'format' => strtoupper($firstRecord->parentRecord->format->name ?? ''),
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'discounted_unit_price' => $discountedUnitPrice,
                    'total' => $rowTotal,
                ];
            });

        // Add summary to data array (matching original format exactly)
        $data[] = [''];  // Empty row
        $data[] = [''];  // Empty row
        $data[] = ['Q', 'Formato', 'Prezzo unitario', 'Sconto', 'Prezzo unitario scontato', 'Totale'];

        $summaryTotal = 0;
        foreach ($summary as $summaryRow) {
            $data[] = [
                $summaryRow['quantity'],
                $summaryRow['format'],
                $summaryRow['unit_price'],
                $summaryRow['discount'],
                $summaryRow['discounted_unit_price'],
                $summaryRow['total'],
            ];

            // Accumulate numeric total
            $summaryTotal += $summaryRow['total'];
        }

        // Final total row (matching original format)
        $data[] = ['Totale', '', '', '', '', $summaryTotal];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT, // Cat Number column
            'F' => NumberFormat::FORMAT_TEXT, // Barcode column
        ];
    }

    protected function barcodeCellRange(): array
    {
        // Column headers on row 7, data rows start at row 8
        return ['F', 8, 7 + $this->wholesaleOut->records()->get()->count()];
    }

    public function styles(Worksheet $sheet)
    {
        // All records are used (not only the shipped ones) to size the summary section
        $shippedRecords = $this->wholesaleOut->records()->get();

        // Calculate the summary header row (after the data rows)
        $dataRowCount = $shippedRecords->count();
        $summaryHeaderRow = 7 + $dataRowCount + 3; // 7 (header) + data rows + 2 empty rows + 1 for header
        $summaryEndRow = $summaryHeaderRow + $this->getSummaryRowCount($shippedRecords) + 1; // Add summary data rows + 1 for final total row

        $money = NumberFormat::FORMAT_CURRENCY_EUR; // '#,##0.00_-[$€]' (money type)

        return [
            // Money display format for price columns (values stay numeric for formulas)
            'I8:I9999' => [
                'numberFormat' => ['formatCode' => $money],
            ],
            'K8:K9999' => [
                'numberFormat' => ['formatCode' => $money],
            ],
            // Style for header rows (1-6)
            '1:6' => [
                'font' => [
                    'bold' => true,
                ],
            ],
            // Right align the total value in header (B5)
            'B5' => [
                'font' => [
                    'bold' => true,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
                'numberFormat' => ['formatCode' => $money],
            ],
            // Style for column headers (row 7)
            '7:7' => [
                'font' => [
                    'bold' => true,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                ],
            ],
            // Left align the Cat Number column (A) for data rows
            'A8:A9999' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                ],
            ],
            // Left align the Barcode column (F) for data rows
            'F8:F9999' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                ],
            ],
            // Right align price columns (H, J) for data rows
            'H8:H9999' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
            ],
            'J8:J9999' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
            ],
            // Style for summary header row (Q, Formato, etc.)
            $summaryHeaderRow.':'.$summaryHeaderRow => [
                'font' => [
                    'bold' => true,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                ],
            ],
            // Right align price columns in summary section (C, E, F)
            'C'.($summaryHeaderRow + 1).':C9999' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
                'numberFormat' => ['formatCode' => $money],
            ],
            'E'.($summaryHeaderRow + 1).':E9999' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
                'numberFormat' => ['formatCode' => $money],
            ],
            'F'.($summaryHeaderRow + 1).':F9999' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
                'numberFormat' => ['formatCode' => $money],
            ],
            // Style for final total row
            $summaryEndRow.':'.$summaryEndRow => [
                'font' => [
                    'bold' => true,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                ],
            ],
            // Right align the final total value (column F in final total row)
            'F'.$summaryEndRow => [
                'font' => [
                    'bold' => true,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
            ],
        ];
    }

    /**
     * Calculate the number of summary data rows
     */
    private function getSummaryRowCount($shippedRecords = null): int
    {
        // Use shipped records if provided, otherwise use all records (for backward compatibility)
        $recordsToCount = $shippedRecords ?? $this->wholesaleOut->records;

        return $recordsToCount
            ->groupBy(function ($record) {
                return $record->parentRecord->format_id.'_'.
                       $record->unit_price->getAmount().'_'.
                       ($record->discount ?? 0);
            })
            ->count();
    }
}
