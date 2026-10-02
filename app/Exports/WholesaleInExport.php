<?php

namespace App\Exports;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Exports\Concerns\FormatsBarcodeColumnAsText;
use App\Models\WholesaleIn;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WholesaleInExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithStyles
{
    use FormatsBarcodeColumnAsText;

    protected WholesaleIn $wholesaleIn;

    public function __construct(WholesaleIn $wholesaleIn)
    {
        $this->wholesaleIn = $wholesaleIn;
    }

    public function array(): array
    {
        // Load the wholesale in with necessary relationships
        $this->wholesaleIn->load(['supplier', 'area', 'records.parentRecord.artist', 'records.parentRecord.label', 'records.parentRecord.format']);

        // Calculate total pieces
        $totalPieces = $this->wholesaleIn->records->sum('quantity');

        // Build the array starting with header information
        $data = [
            // Header rows (6 rows)
            ['Numero documento', $this->wholesaleIn->doc_num],
            ['Fornitore', $this->wholesaleIn->supplier?->name ?? ''],
            ['Data', $this->wholesaleIn->created_at?->format('H:i:s d/m/Y') ?? ''],
            ['Luogo', $this->wholesaleIn->area?->name ?? ''],
            ['Pezzi', $totalPieces],
            ['Totale', $this->wholesaleIn->total_price ? $this->wholesaleIn->total_price->getAmount() / 100 : 0],
            [$this->wholesaleIn->description ?? ''],

            // Column headers for the data table (row 10)
            [
                'cat#',
                'artist',
                'title',
                'fmt',
                'label',
                'barcode',
                'q',
                'prezzo dettaglio',
                'prezzo ingrosso',
                'prezzo di acquisto',
                'discount',
                'total',
                'iva',
                'condition_disk',
                'condition_cover',
            ],
        ];

        // Add the data rows
        foreach ($this->wholesaleIn->records as $wholesaleInRecord) {
            $record = $wholesaleInRecord->parentRecord;

            $data[] = [
                $record?->cat_number ?? '',
                $record?->artist?->name ?? '',
                $record?->title ?? '',
                $record?->format?->name ?? '',
                $record?->label?->name ?? '',
                $record?->barcode ?? '',
                $wholesaleInRecord->quantity ?? 0,
                $wholesaleInRecord->unit_price ? $wholesaleInRecord->unit_price->getAmount() / 100 : 0,
                $record->wholesale_price ? $record->wholesale_price->getAmount() / 100 : 0,
                $record->purchase_price ? $record->purchase_price->getAmount() / 100 : 0,
                $wholesaleInRecord->discount ?? 0,
                $wholesaleInRecord->total_price ? $wholesaleInRecord->total_price->getAmount() / 100 : 0,
                $wholesaleInRecord->vat ?? 0,
                $record?->disk_status !== null ? DiskStatusEnum::from($record->disk_status)->getDescription() : '',
                $record?->cover_status !== null ? CoverStatusEnum::from($record->cover_status)->getDescription() : '',
            ];
        }

        $this->addSummarySection($data);

        return $data;
    }

    protected function addSummarySection(array &$data, $chargeRecords = null): void
    {
        // Use shipped records if provided, otherwise use all records (for backward compatibility)
        $recordsToSummarize = $chargeRecords ?? $this->wholesaleIn->records;

        // Group records by format, unit_price, and discount for summary (matching original SQL logic)
        $summary = $recordsToSummarize
            ->groupBy(function ($record) {
                return $record->parentRecord->format_id.'_'.
                       $record->unit_price->getAmount().'_'.
                       ($record->discount ?? 0);
            })
            ->map(function ($groupedRecords) {
                $firstRecord = $groupedRecords->first();
                $totalQuantity = $groupedRecords->sum('quantity');

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
            'A' => NumberFormat::FORMAT_TEXT, // Cat Number column (data starts from row 11)
            'F' => NumberFormat::FORMAT_TEXT, // Barcode column
        ];
    }

    protected function barcodeCellRange(): array
    {
        // Column headers on row 8, data rows start at row 9
        return ['F', 9, 8 + $this->wholesaleIn->records->count()];
    }

    public function styles(Worksheet $sheet)
    {

        $chargeRecords = $this->wholesaleIn->records->filter(function ($record) {
            return $record->quantity > 0;
        });

        // Calculate the summary header row (after the data rows)
        $dataRowCount = $chargeRecords->count();
        $summaryRowCount = $this->getSummaryRowCount($chargeRecords);

        // Header: 7, colonne: 1, dati: $dataRowCount, vuote: 2
        $summaryHeaderRow = 8 + $dataRowCount + 2 + 1; // 8 (colonne) + dati + 2 vuote + 1 header riepilogo
        $summaryDataStartRow = $summaryHeaderRow + 1;
        $summaryEndRow = $summaryHeaderRow + $summaryRowCount + 1; // +1 per la riga totale finale

        $money = NumberFormat::FORMAT_CURRENCY_EUR; // '#,##0.00_-[$€]' (money type)

        return [
            // Money display format for price columns (values stay numeric for formulas)
            'H9:J9999' => [
                'numberFormat' => ['formatCode' => $money],
            ],
            'L9:L9999' => [
                'numberFormat' => ['formatCode' => $money],
            ],
            // Style for header rows (1-6)
            '1:7' => [
                'font' => [
                    'bold' => true,
                ],
            ],
            'B6' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
                'numberFormat' => ['formatCode' => $money],
            ],
            // Style for column headers (row 10)
            '8:8' => [
                'font' => [
                    'bold' => true,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                ],
            ],
            // Cat Number
            'A9:A'.(8 + $dataRowCount) => [
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ],
            // Riepilogo header (grassetto)
            $summaryHeaderRow.':'.$summaryHeaderRow => [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ],
            // Riepilogo dati (allinea prezzi)
            'C'.$summaryDataStartRow.':C'.$summaryEndRow => [
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                'numberFormat' => ['formatCode' => $money],
            ],
            'E'.$summaryDataStartRow.':E'.$summaryEndRow => [
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                'numberFormat' => ['formatCode' => $money],
            ],
            'F'.$summaryDataStartRow.':F'.$summaryEndRow => [
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                'numberFormat' => ['formatCode' => $money],
            ],
            // Totale finale (grassetto)
            $summaryEndRow.':'.$summaryEndRow => [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ],
            // Totale finale colonna F (grassetto e destra)
            'F'.$summaryEndRow => [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            ],
        ];
    }

    /**
     * Calculate the number of summary data rows
     */
    private function getSummaryRowCount($chargeRecords = null): int
    {
        // Use shipped records if provided, otherwise use all records (for backward compatibility)
        $recordsToCount = $chargeRecords ?? $this->wholesaleIn->records;

        return $recordsToCount
            ->groupBy(function ($record) {
                return $record->parentRecord->format_id.'_'.
                       $record->unit_price->getAmount().'_'.
                       ($record->discount ?? 0);
            })
            ->count();
    }
}
