<?php

namespace Tests\Feature\RecordsImport;

use App\Enums\RolesEnum;
use App\Imports\RecordsImport;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Tests for RecordsImport parser matching priority: record_id > barcode > cat_number
 */
class ParserPriorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(RolesEnum::Admin->value);
        Auth::login($user);

        Format::factory()->create();
        Label::factory()->create();
        Artist::factory()->create();
    }

    /**
     * Create a temporary CSV file with the given rows.
     */
    private function createCsv(array $headers, array $rows): UploadedFile
    {
        $content = implode(',', $headers)."\n";
        foreach ($rows as $row) {
            $content .= implode(',', $row)."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'test_import_').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'test.csv', 'text/csv', null, true);
    }

    public function test_record_id_takes_priority_over_barcode(): void
    {
        // Create two records with different barcodes
        $recordA = Record::factory()->create(['barcode' => '1111111111']);
        $recordB = Record::factory()->create(['barcode' => '1111111111']); // Same barcode

        $import = new RecordsImport;
        $file = $this->createCsv(
            ['ID', 'cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q'],
            [[$recordB->id, '', 'Artist', 'Title', 'LP', 'Label', '1111111111', 1]]
        );

        $import->import($file);
        $data = $import->getProcessedData();

        $this->assertCount(1, $data);
        // Should match recordB by ID, not recordA (which barcode->first() might return)
        $this->assertEquals($recordB->id, $data[0]['record_id']);
        $this->assertTrue($data[0]['exist']);
    }

    public function test_barcode_takes_priority_over_cat_number(): void
    {
        $recordByBarcode = Record::factory()->create([
            'barcode' => '2222222222',
            'cat_number' => 'OTHER-CAT',
        ]);
        $recordByCat = Record::factory()->create([
            'barcode' => '3333333333',
            'cat_number' => 'MATCH-CAT',
        ]);

        $import = new RecordsImport;
        $file = $this->createCsv(
            ['ID', 'cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q'],
            [
                [0, 'MATCH-CAT', 'Artist', 'Title', 'LP', 'Label', '2222222222', 1],
            ]
        );

        $import->import($file);
        $data = $import->getProcessedData();

        // Should match by barcode (2222222222 -> recordByBarcode), not by cat_number
        $this->assertEquals($recordByBarcode->id, $data[0]['record_id']);
    }

    public function test_falls_back_to_cat_number_when_no_barcode(): void
    {
        $record = Record::factory()->create([
            'barcode' => '4444444444',
            'cat_number' => 'FALLBACK-CAT',
        ]);

        $import = new RecordsImport;
        $file = $this->createCsv(
            ['ID', 'cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q'],
            [
                [0, 'FALLBACK-CAT', 'Artist', 'Title', 'LP', 'Label', '', 1],
            ]
        );

        $import->import($file);
        $data = $import->getProcessedData();

        $this->assertEquals($record->id, $data[0]['record_id']);
        $this->assertTrue($data[0]['exist']);
    }

    public function test_no_match_returns_record_id_zero(): void
    {
        $import = new RecordsImport;
        $file = $this->createCsv(
            ['ID', 'cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q'],
            [
                [0, 'NONEXISTENT-CAT', 'Artist', 'Title', 'LP', 'Label', '0000000000', 1],
            ]
        );

        $import->import($file);
        $data = $import->getProcessedData();

        $this->assertEquals(0, $data[0]['record_id']);
        $this->assertFalse($data[0]['exist']);
    }
}
