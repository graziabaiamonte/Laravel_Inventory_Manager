<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for Record model's auto-fill barcode behaviour (booted created event).
 */
class RecordAutoFillBarcodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Format::factory()->create();
        Label::factory()->create();
        Artist::factory()->create();
    }

    public function test_auto_fills_barcode_with_rr_uid_when_both_empty(): void
    {
        $record = Record::factory()->create([
            'barcode' => '',
            'cat_number' => '',
        ]);

        $record->refresh();

        $this->assertEquals('RAD'.$record->id, $record->rr_uid);
        $this->assertEquals($record->rr_uid, $record->barcode);
    }

    public function test_does_not_auto_fill_when_barcode_present(): void
    {
        $record = Record::factory()->create([
            'barcode' => '1234567890',
            'cat_number' => '',
        ]);

        $record->refresh();

        $this->assertEquals('1234567890', $record->barcode);
    }

    public function test_does_not_auto_fill_when_cat_number_present(): void
    {
        $record = Record::factory()->create([
            'barcode' => '',
            'cat_number' => 'ABC-001',
        ]);

        $record->refresh();

        $this->assertEmpty($record->barcode);
        $this->assertEquals('ABC-001', $record->cat_number);
    }

    public function test_does_not_auto_fill_when_both_present(): void
    {
        $record = Record::factory()->create([
            'barcode' => '9876543210',
            'cat_number' => 'XYZ-999',
        ]);

        $record->refresh();

        $this->assertEquals('9876543210', $record->barcode);
        $this->assertEquals('XYZ-999', $record->cat_number);
    }

    public function test_rr_uid_is_always_set(): void
    {
        $record = Record::factory()->create([
            'barcode' => '1111111111',
            'cat_number' => 'CAT-1',
        ]);

        $record->refresh();

        $this->assertEquals('RAD'.$record->id, $record->rr_uid);
    }
}
