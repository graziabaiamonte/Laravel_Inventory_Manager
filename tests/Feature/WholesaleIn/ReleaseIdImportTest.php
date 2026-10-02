<?php

namespace Tests\Feature\WholesaleIn;

use App\Imports\WholesaleInImport;
use App\Models\Record;
use App\Models\Stock;
use App\Services\External\DiscogsListingService;
use Illuminate\Support\Facades\Http;

/**
 * The wholesale-in import accepts a "release_id (discogs)" column and stores it into
 * records.release_id, so a brand-new record imported as a WI can be published on Discogs
 * straight away (Release ID + for sale + stock), without editing the record first.
 */
class ReleaseIdImportTest extends WholesaleInTestCase
{
    public function test_import_parses_release_id_discogs_column(): void
    {
        $this->actingAs($this->user);

        $import = new WholesaleInImport;
        $import->array([
            ['cat#', 'title', 'barcode', 'q', 'for_sale_on_discogs', 'release_id (discogs)'],
            ['NEWCAT-411', 'New Title', '', '2', '1', '36098392'],
        ]);

        $data = $import->getProcessedData();

        $this->assertCount(1, $data);
        $this->assertEquals(0, $data[0]['record_id'], 'should be a new record');
        $this->assertEquals(36098392, (int) $data[0]['release_id']);
    }

    public function test_store_persists_release_id_for_new_record(): void
    {
        Http::fake();

        $catNumber = 'RELEASE-IMPORT-411';

        $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'REL-411',
            'description' => 'Test',
            'status' => 0,
            'records' => [
                [
                    'record_id' => 0,
                    'cat_number' => $catNumber,
                    'title' => 'Release Import Rec',
                    'quantity' => 3,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 30.00,
                    'vat' => 22,
                    'for_sale_on_discogs' => 1,
                    'release_id' => 36098392,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 3],
                    ],
                ],
            ],
        ]);

        $record = Record::where('cat_number', $catNumber)->first();

        $this->assertNotNull($record);
        $this->assertEquals(36098392, $record->release_id, 'release_id must be persisted from the import');
        $this->assertEquals(1, $record->for_sale_on_discogs);
        $this->assertNull($record->discogs_id, 'listing ID is still only set from Discogs confirmation');
    }

    public function test_new_record_without_release_id_stores_null_not_zero(): void
    {
        Http::fake();

        $catNumber = 'NO-RELEASE-411';

        $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'REL-411-NONE',
            'description' => 'Test',
            'status' => 0,
            'records' => [
                [
                    'record_id' => 0,
                    'cat_number' => $catNumber,
                    'title' => 'No Release Rec',
                    'quantity' => 3,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 30.00,
                    'vat' => 22,
                    'for_sale_on_discogs' => 0,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 3],
                    ],
                ],
            ],
        ]);

        $record = Record::where('cat_number', $catNumber)->first();

        $this->assertNotNull($record);
        $this->assertNull($record->release_id, 'a missing Release ID must be stored as null, not 0');
    }

    public function test_import_backfills_release_id_for_existing_record_without_one(): void
    {
        Http::fake();

        // A record that already exists but was never given a Release ID (e.g. created before the
        // release_id import feature). Flagging it for sale is useless until it gets a release_id.
        $record = Record::factory()->create([
            'barcode' => '769152434513',
            'release_id' => 0,
            'for_sale_on_discogs' => 0,
            'discogs_id' => null,
        ]);

        $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'REL-411-EXISTING',
            'description' => 'Test',
            'status' => 0,
            'records' => [
                [
                    'record_id' => $record->id,
                    'barcode' => '769152434513',
                    'quantity' => 4,
                    'unit_price' => 19.44,
                    'discount' => 0,
                    'total_price' => 77.76,
                    'vat' => 22,
                    'for_sale_on_discogs' => 1,
                    'release_id' => 37668123,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 4],
                    ],
                ],
            ],
        ]);

        $record->refresh();

        $this->assertEquals(37668123, $record->release_id, 'release_id must be backfilled from the import for an existing record');
        $this->assertEquals(1, $record->for_sale_on_discogs);
    }

    public function test_import_does_not_overwrite_existing_release_id(): void
    {
        Http::fake();

        $record = Record::factory()->create([
            'barcode' => '769152434513',
            'release_id' => 11111111,
            'for_sale_on_discogs' => 0,
            'discogs_id' => null,
        ]);

        $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'REL-411-KEEP',
            'description' => 'Test',
            'status' => 0,
            'records' => [
                [
                    'record_id' => $record->id,
                    'barcode' => '769152434513',
                    'quantity' => 4,
                    'unit_price' => 19.44,
                    'discount' => 0,
                    'total_price' => 77.76,
                    'vat' => 22,
                    'for_sale_on_discogs' => 1,
                    'release_id' => 37668123,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 4],
                    ],
                ],
            ],
        ]);

        $this->assertEquals(11111111, $record->refresh()->release_id, 'an existing valid release_id must not be clobbered by the import');
    }

    public function test_new_record_with_imported_release_id_can_be_listed_immediately(): void
    {
        // Discogs confirms the listing with a real listing_id.
        Http::fake(['*' => Http::response(['listing_id' => 999999], 200)]);

        $record = Record::factory()->create([
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
            'release_id' => 36098392, // as if imported via the WI file
            'for_sale_on_discogs' => 1,
            'discogs_id' => null,
        ]);
        Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area1->id,
            'quantity' => 5,
        ]);

        $result = app(DiscogsListingService::class)->listRecordOnDiscogs($record->fresh());

        $this->assertTrue($result['success'], 'a record with a Release ID must be publishable straight away');
        $this->assertEquals(999999, $record->fresh()->discogs_id, 'discogs_id comes from Discogs confirmation');
    }
}
