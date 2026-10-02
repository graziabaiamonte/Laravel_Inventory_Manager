<?php

namespace Tests\Feature\WholesaleIn;

use App\Models\Record;
use App\Models\WholesaleIn;

/**
 * Tests for duplicate barcode/cat_number warnings during WholesaleIn save.
 */
class DuplicateWarningsTest extends WholesaleInTestCase
{
    // --- F3: Save WholesaleIn with duplicate barcode warning ---

    public function test_save_with_duplicate_barcode_shows_warning(): void
    {
        // Create 2 records with same barcode
        $record1 = Record::factory()->create([
            'barcode' => '2299991167895',
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);
        $record2 = Record::factory()->create([
            'barcode' => '2299991167895',
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);

        // Save WholesaleIn referencing one of the duplicate-barcode records
        $response = $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'WI-DUPE-BC',
            'description' => 'Test duplicate barcode',
            'status' => 0,
            'records' => [
                [
                    'record_id' => $record1->id,
                    'quantity' => 5,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 50.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 5],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning');
        $warning = session('warning');
        $this->assertStringContainsString('2299991167895', $warning);
        $this->assertStringContainsString('Vedi duplicati', $warning);
    }

    // --- F4: Save WholesaleIn with duplicate cat_number warning ---

    public function test_save_with_duplicate_cat_number_shows_warning(): void
    {
        $record1 = Record::factory()->create([
            'cat_number' => 'DUPE-CAT-WI',
            'barcode' => '1000000001',
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);
        $record2 = Record::factory()->create([
            'cat_number' => 'DUPE-CAT-WI',
            'barcode' => '1000000002',
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);

        $response = $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'WI-DUPE-CAT',
            'description' => 'Test duplicate cat_number',
            'status' => 0,
            'records' => [
                [
                    'record_id' => $record1->id,
                    'quantity' => 5,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 50.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 5],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning');
        $warning = session('warning');
        $this->assertStringContainsString('DUPE-CAT-WI', $warning);
    }

    // --- F1: Save WholesaleIn with new record (no barcode/cat_number) gets auto-barcode warning ---

    public function test_save_new_record_without_identifiers_shows_auto_barcode_warning(): void
    {
        $response = $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'WI-AUTO-BC',
            'description' => 'Test auto barcode',
            'status' => 0,
            'records' => [
                [
                    'record_id' => 0, // New record
                    'quantity' => 3,
                    'unit_price' => 5.00,
                    'discount' => 0,
                    'total_price' => 15.00,
                    'vat' => 22,
                    'title' => 'Auto Barcode Record',
                    'artist' => 'Test Artist',
                    'format' => $this->format->name,
                    'label' => $this->label->name,
                    'barcode' => '',
                    'cat_number' => '',
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 3],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        // The new record should have auto-generated barcode = rr_uid
        $newRecord = Record::where('title', 'Auto Barcode Record')->first();
        $this->assertNotNull($newRecord);
        $this->assertEquals('RAD'.$newRecord->id, $newRecord->barcode);

        // Warning about auto-generated barcode should be flashed
        $response->assertSessionHas('warning');
        $warning = session('warning');
        $this->assertStringContainsString($newRecord->rr_uid, $warning);
    }

    // --- No warning when barcode/cat_number are unique ---

    public function test_save_with_unique_barcode_no_warning(): void
    {
        $record = Record::factory()->create([
            'barcode' => '9999999901',
            'cat_number' => 'UNIQUE-WI-CAT',
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);

        $response = $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'WI-UNIQUE',
            'description' => 'Test unique',
            'status' => 0,
            'records' => [
                [
                    'record_id' => $record->id,
                    'quantity' => 5,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 50.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 5],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('warning');
    }
}
