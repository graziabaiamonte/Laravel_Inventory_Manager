<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Forces the barcode column's data cells to be stored as explicit text.
 *
 * PhpSpreadsheet's default value binder stores numeric-looking barcodes as
 * numbers, so a FORMAT_TEXT display format alone is not enough - the stored
 * value must be an explicit string. This trait rewrites the cells in the
 * barcode data range (bounded so it never touches summary/money rows in the
 * same column) after the sheet has been populated.
 */
trait FormatsBarcodeColumnAsText
{
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                [$column, $firstRow, $lastRow] = $this->barcodeCellRange();
                $sheet = $event->sheet->getDelegate();

                for ($row = $firstRow; $row <= $lastRow; $row++) {
                    $cell = $sheet->getCell($column.$row);
                    $cell->setValueExplicit((string) $cell->getValue(), DataType::TYPE_STRING);
                }
            },
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: int} [column letter, first data row, last data row]
     */
    abstract protected function barcodeCellRange(): array;
}
