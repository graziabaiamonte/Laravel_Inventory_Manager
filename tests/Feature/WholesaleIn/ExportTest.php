<?php

namespace Tests\Feature\WholesaleIn;

use App\Exports\WholesaleInExport;
use App\Models\WholesaleIn;
use App\Models\WholesaleInRecord;
use Tests\Concerns\ReadsExportedSpreadsheet;

class ExportTest extends WholesaleInTestCase
{
    use ReadsExportedSpreadsheet;

    private const BARCODE_A = '8012345678901';   // 13 digits, no leading zero -> would bind numeric

    private const BARCODE_B = '0012345678905';   // leading zeros -> would be dropped as a number

    private function makeWholesaleIn(): WholesaleIn
    {
        $this->record1->update(['barcode' => self::BARCODE_A]);
        $this->record2->update(['barcode' => self::BARCODE_B]);

        $wholesaleIn = WholesaleIn::factory()->active()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
        ]);

        // Same unit_price + discount so the summary groups into a single row (deterministic layout)
        foreach ([$this->record1, $this->record2] as $record) {
            WholesaleInRecord::factory()->create([
                'wholesale_in_id' => $wholesaleIn->id,
                'record_id' => $record->id,
                'quantity' => 5,
                'unit_price' => 10.00,
                'discount' => 0,
                'total_price' => 50.00,
                'vat' => 22,
            ]);
        }

        return $wholesaleIn->fresh();
    }

    public function test_export_route_downloads_xlsx(): void
    {
        $wholesaleIn = $this->makeWholesaleIn();

        $response = $this->actingAs($this->user)
            ->get(route('wholesale-in.export', $wholesaleIn));

        $response->assertOk();
        $this->assertStringContainsString('Carico_', $response->headers->get('content-disposition'));
    }

    public function test_barcode_cells_are_stored_as_text(): void
    {
        $sheet = $this->loadExportSheet(new WholesaleInExport($this->makeWholesaleIn()));

        // Column headers on row 8, data rows start at row 9
        $this->assertCellText($sheet, 'F8', 'barcode');
        $this->assertCellText($sheet, 'F9', self::BARCODE_A);
        $this->assertCellText($sheet, 'F10', self::BARCODE_B);
    }

    public function test_summary_total_in_barcode_column_stays_numeric(): void
    {
        $sheet = $this->loadExportSheet(new WholesaleInExport($this->makeWholesaleIn()));

        // Layout with 2 data rows + 1 summary group:
        // rows 9-10 data, 11-12 empty, 13 summary header, 14 group, 15 final "Totale" (money in col F)
        $this->assertSame('Totale', (string) $sheet->getCell('A15')->getValue());
        $this->assertCellNumeric($sheet, 'F15');
    }
}
