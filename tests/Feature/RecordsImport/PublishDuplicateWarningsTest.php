<?php

namespace Tests\Feature\RecordsImport;

use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use App\Models\RecordsImport;
use App\Models\RecordsImportRecordTmp;
use App\Services\RecordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for duplicate barcode/cat_number warnings during Mass Import publish.
 */
class PublishDuplicateWarningsTest extends TestCase
{
    use RefreshDatabase;

    protected RecordImportService $service;

    protected Format $format;

    protected Label $label;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        Format::factory()->create();
        Label::factory()->create();
        Artist::factory()->create();

        $this->format = Format::first();
        $this->label = Label::first();
        $this->artist = Artist::first();

        $this->service = app(RecordImportService::class);
    }

    /**
     * Create a tmp record in a draft import.
     */
    private function createTmpRecord(RecordsImport $import, array $overrides = []): RecordsImportRecordTmp
    {
        return RecordsImportRecordTmp::create(array_merge([
            'records_import_id' => $import->id,
            'barcode' => '',
            'cat_number' => '',
            'title' => 'Test Import Record',
            'type' => 'new',
            'retail_price' => 0,
            'wholesale_price' => 0,
            'purchase_price' => 0,
            'disk_status' => 0,
            'cover_status' => 0,
            'for_sale_on_discogs' => 0,
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
            'record_id' => null,
        ], $overrides));
    }

    // --- C2: Publish with duplicate barcode warns ---

    public function test_publish_warns_on_duplicate_barcode_for_existing_record(): void
    {
        // Create 2 records with same barcode in DB
        $record1 = Record::factory()->create(['barcode' => '2299991167895']);
        $record2 = Record::factory()->create(['barcode' => '2299991167895']);

        $import = RecordsImport::create(['draft' => 1]);

        // Tmp record pointing to one of the existing records
        $this->createTmpRecord($import, [
            'record_id' => $record1->id,
            'barcode' => '2299991167895',
            'cat_number' => 'UNIQUE-CAT',
        ]);

        $result = $this->service->publishImport($import);

        $this->assertNotEmpty($result['duplicate_warnings']);
        $this->assertStringContainsString('2299991167895', $result['duplicate_warnings'][0]);
        $this->assertStringContainsString('Vedi duplicati', $result['duplicate_warnings'][0]);
    }

    // --- C4: Publish with duplicate cat_number warns ---

    public function test_publish_warns_on_duplicate_cat_number_for_existing_record(): void
    {
        $record1 = Record::factory()->create(['cat_number' => 'DUPE-CAT-123']);
        $record2 = Record::factory()->create(['cat_number' => 'DUPE-CAT-123']);

        $import = RecordsImport::create(['draft' => 1]);

        $this->createTmpRecord($import, [
            'record_id' => $record1->id,
            'barcode' => '',
            'cat_number' => 'DUPE-CAT-123',
        ]);

        $result = $this->service->publishImport($import);

        $this->assertNotEmpty($result['duplicate_warnings']);
        $this->assertStringContainsString('DUPE-CAT-123', $result['duplicate_warnings'][0]);
    }

    // --- New record with duplicate barcode warns ---

    public function test_publish_warns_on_duplicate_barcode_for_new_record(): void
    {
        // Create existing record with this barcode
        Record::factory()->create(['barcode' => '5555555555']);

        $import = RecordsImport::create(['draft' => 1]);

        // New record (no record_id) with same barcode
        $this->createTmpRecord($import, [
            'record_id' => null,
            'barcode' => '5555555555',
            'cat_number' => 'NEW-CAT',
        ]);

        $result = $this->service->publishImport($import);

        // A new record was created with the same barcode, so 2 total
        $this->assertEquals(2, Record::where('barcode', '5555555555')->count());

        $this->assertNotEmpty($result['duplicate_warnings']);
        $this->assertStringContainsString('5555555555', $result['duplicate_warnings'][0]);
    }

    // --- No warning when unique ---

    public function test_publish_no_warning_when_barcode_unique(): void
    {
        $record = Record::factory()->create(['barcode' => '7777777777']);

        $import = RecordsImport::create(['draft' => 1]);

        $this->createTmpRecord($import, [
            'record_id' => $record->id,
            'barcode' => '7777777777',
            'cat_number' => 'UNIQUE-CAT',
        ]);

        $result = $this->service->publishImport($import);

        $this->assertEmpty($result['duplicate_warnings']);
    }

    // --- Auto-barcode tracking ---

    public function test_publish_tracks_auto_barcode_records(): void
    {
        $import = RecordsImport::create(['draft' => 1]);

        // New record with no barcode and no cat_number
        $this->createTmpRecord($import, [
            'record_id' => null,
            'barcode' => '',
            'cat_number' => '',
            'title' => 'No Identifiers Record',
        ]);

        $result = $this->service->publishImport($import);

        $this->assertNotEmpty($result['auto_barcode_records']);
        $autoRecord = $result['auto_barcode_records'][0];
        $this->assertEquals('RAD'.$autoRecord->id, $autoRecord->barcode);
        $this->assertEquals($autoRecord->rr_uid, $autoRecord->barcode);
    }
}
