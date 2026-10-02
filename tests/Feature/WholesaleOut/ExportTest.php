<?php

namespace Tests\Feature\WholesaleOut;

use App\Exports\WholesaleOutExport;
use App\Models\WholesaleOut;
use Tests\Concerns\ReadsExportedSpreadsheet;

class ExportTest extends WholesaleOutTestCase
{
    use ReadsExportedSpreadsheet;

    private const BARCODE_A = '8012345678901';   // 13 digits, no leading zero -> would bind numeric

    private const BARCODE_B = '0012345678905';   // leading zeros -> would be dropped as a number

    private function makeWholesaleOut(): WholesaleOut
    {
        $this->record1->update(['barcode' => self::BARCODE_A]);
        $this->record2->update(['barcode' => self::BARCODE_B]);

        // Same unit_price + discount so the summary groups into a single row (deterministic layout).
        // Positions mirror real data: position 0 = most recently added, so record1 (added first)
        // gets the higher position. The export lists oldest-added first -> record1 on row 8.
        return $this->createActiveWholesaleOut([
            ['record_id' => $this->record1->id, 'quantity' => 5, 'unit_price' => 1000, 'discount' => 0, 'position' => 1],
            ['record_id' => $this->record2->id, 'quantity' => 3, 'unit_price' => 1000, 'discount' => 0, 'position' => 0],
        ]);
    }

    public function test_export_route_downloads_xlsx(): void
    {
        $wholesaleOut = $this->makeWholesaleOut();

        $response = $this->actingAs($this->user)
            ->get(route('wholesale-out.export', $wholesaleOut));

        $response->assertOk();
        $this->assertStringContainsString('Scarico_', $response->headers->get('content-disposition'));
    }

    public function test_barcode_cells_are_stored_as_text(): void
    {
        $sheet = $this->loadExportSheet(new WholesaleOutExport($this->makeWholesaleOut()));

        // Column headers on row 7, data rows start at row 8
        $this->assertCellText($sheet, 'F7', 'barcode');
        $this->assertCellText($sheet, 'F8', self::BARCODE_A);
        $this->assertCellText($sheet, 'F9', self::BARCODE_B);
    }

    public function test_summary_total_in_barcode_column_stays_numeric(): void
    {
        $sheet = $this->loadExportSheet(new WholesaleOutExport($this->makeWholesaleOut()));

        // 2 data rows (8-9) + 2 empty (10-11) + summary header (12) + group (13) + final "Totale" (14)
        $this->assertSame('Totale', (string) $sheet->getCell('A14')->getValue());
        $this->assertCellNumeric($sheet, 'F14');
    }
}
