<?php

namespace Tests\Feature\Backorder;

use App\Enums\RolesEnum;
use App\Exports\BackorderExport;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Backorder;
use App\Models\BackorderRecord;
use App\Models\Customer;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use App\Models\User;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ReadsExportedSpreadsheet;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use ReadsExportedSpreadsheet;
    use RefreshDatabase;

    private const BARCODE_A = '8012345678901';   // 13 digits, no leading zero -> would bind numeric

    private const BARCODE_B = '0012345678905';   // leading zeros -> would be dropped as a number

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $this->user = User::factory()->create();
        $this->user->assignRole(RolesEnum::Admin->value);
    }

    private function makeBackorder(): Backorder
    {
        $format = Format::factory()->create();
        $label = Label::factory()->create();
        $artist = Artist::factory()->create();

        $recordA = Record::factory()->create([
            'format_id' => $format->id, 'label_id' => $label->id, 'artist_id' => $artist->id,
            'barcode' => self::BARCODE_A,
        ]);
        $recordB = Record::factory()->create([
            'format_id' => $format->id, 'label_id' => $label->id, 'artist_id' => $artist->id,
            'barcode' => self::BARCODE_B,
        ]);

        $wholesaleOut = WholesaleOut::factory()->active()->create([
            'customer_id' => Customer::factory()->create()->id,
            'area_id' => Area::factory()->create()->id,
        ]);

        $backorder = Backorder::factory()->completed()->create([
            'wholesale_out_id' => $wholesaleOut->id,
        ]);

        // Same unit_price + discount so the summary groups into a single row (deterministic layout)
        foreach ([$recordA, $recordB] as $record) {
            $outRecord = WholesaleOutRecord::factory()->create([
                'wholesale_out_id' => $wholesaleOut->id,
                'record_id' => $record->id,
                'quantity' => 5,
                'unit_price' => 1000,
                'discount' => 0,
            ]);

            BackorderRecord::create([
                'backorder_id' => $backorder->id,
                'wholesale_out_record_id' => $outRecord->id,
                'quantity' => 5,
                'shipped_quantity' => 5,
            ]);
        }

        return $backorder->fresh();
    }

    public function test_export_route_downloads_xlsx(): void
    {
        $backorder = $this->makeBackorder();

        $response = $this->actingAs($this->user)
            ->get(route('backorder.export', $backorder));

        $response->assertOk();
        $this->assertStringContainsString('Backorder_', $response->headers->get('content-disposition'));
    }

    public function test_barcode_cells_are_stored_as_text(): void
    {
        $sheet = $this->loadExportSheet(new BackorderExport($this->makeBackorder()));

        // Column headers on row 7, data rows start at row 8
        $this->assertCellText($sheet, 'F7', 'barcode');
        $this->assertCellText($sheet, 'F8', self::BARCODE_A);
        $this->assertCellText($sheet, 'F9', self::BARCODE_B);
    }

    public function test_summary_total_in_barcode_column_stays_numeric(): void
    {
        $sheet = $this->loadExportSheet(new BackorderExport($this->makeBackorder()));

        // 2 data rows (8-9) + 2 empty (10-11) + summary header (12) + group (13) + final "Totale" (14)
        $this->assertSame('Totale', (string) $sheet->getCell('A14')->getValue());
        $this->assertCellNumeric($sheet, 'F14');
    }
}
