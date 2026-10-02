<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsBarcodeColumnAsText;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RecordExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStyles
{
    use FormatsBarcodeColumnAsText;

    protected Collection $records;

    public function __construct(Collection $records)
    {
        $this->records = $records;
    }

    public function collection()
    {
        return $this->records;
    }

    public function headings(): array
    {

        return [
            'ID',
            'RR UID',
            'cat#',
            'artist',
            'title',
            'fmt',
            'label',
            'price',
            'barcode',
            'q',
            'supplier',
            'pending',
            'order_amount',
        ];
    }

    public function map($record): array
    {
        $supplier = $record->wholesaleInRecords->first()?->wholesaleIn?->supplier;

        $orderQuantity = $record->wholesaleOutRecords->sum('quantity');

        $backorderQuantity = $record->wholesaleOutRecords
            ->flatMap(function ($outRecord) {
                return $outRecord->backorderRecords;
            })
            ->sum('quantity');

        return [
            $record->id,
            $record->rr_uid,
            $record->cat_number,
            $record->artist?->name,
            $record->title,
            $record->format?->name,
            $record->label?->name,
            $record->retail_price ? $record->retail_price->getAmount() / 100 : 0,
            $record->barcode,
            ! empty($record->total_stocks) ? $record->total_stocks : 0,
            $supplier?->name ?? '',
            ! empty($backorderQuantity) ? $backorderQuantity : 0, // pending
            ! empty($orderQuantity) ? $orderQuantity : 0, // order_amount
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_TEXT, // Cat Number column
            'I' => NumberFormat::FORMAT_TEXT, // Barcode column
            'H' => NumberFormat::FORMAT_CURRENCY_EUR, // Price column (money type)
        ];
    }

    protected function barcodeCellRange(): array
    {
        // Heading on row 1, data rows start at row 2 (no summary in this column)
        return ['I', 2, 1 + $this->records->count()];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Left align the Cat Number column (C)
            'C' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                ],
            ],
            // Right align the Barcode column (I)
            'I' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT,
                ],
            ],
        ];
    }
}
