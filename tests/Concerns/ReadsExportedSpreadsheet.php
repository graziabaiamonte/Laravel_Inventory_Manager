<?php

namespace Tests\Concerns;

use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Renders an export to a real .xlsx and lets tests assert on stored cell types.
 *
 * Uses Excel::raw() (not Excel::fake) on purpose: it runs the full writer
 * pipeline including the AfterSheet event, which is what forces barcode cells
 * to text. A faked download would never inspect the actual cell data types.
 */
trait ReadsExportedSpreadsheet
{
    protected function loadExportSheet(object $export): Worksheet
    {
        $raw = Excel::raw($export, ExcelWriter::XLSX);

        $tmp = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($tmp, $raw);

        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        return $sheet;
    }

    protected function assertCellText(Worksheet $sheet, string $coord, ?string $expected = null): void
    {
        $cell = $sheet->getCell($coord);
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), "$coord should be stored as text");

        if ($expected !== null) {
            $this->assertSame($expected, (string) $cell->getValue(), "$coord value mismatch");
        }
    }

    protected function assertCellNumeric(Worksheet $sheet, string $coord): void
    {
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($coord)->getDataType(), "$coord should stay numeric");
    }
}
