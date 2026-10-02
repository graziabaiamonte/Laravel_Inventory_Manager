<?php

namespace Tests\Feature;

use App\Enums\RolesEnum;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for Record barcode/cat_number validation, auto-fill, and duplicate warnings
 * via the Record UI create/edit endpoints.
 */
class RecordBarcodeCatNumberTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Format $format;

    protected Label $label;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(RolesEnum::Admin->value);

        $this->format = Format::factory()->create();
        $this->label = Label::factory()->create();
        $this->artist = Artist::factory()->create();
    }

    /**
     * Base record data for store/update requests.
     */
    private function baseRecordData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Test Record',
            'format_id' => $this->format->id,
            'artist_id' => $this->artist->id,
            'label_id' => $this->label->id,
            'type' => 'new',
            'wholesale_price' => 10.00,
            'disk_status' => 0,
            'cover_status' => 0,
            'for_sale_on_discogs' => 0,
        ], $overrides);
    }

    // --- A1: Create with both barcode and cat_number empty ---

    public function test_create_record_with_empty_barcode_and_cat_number_auto_fills_barcode(): void
    {
        $response = $this->actingAs($this->user)->post(
            route('record.store'),
            $this->baseRecordData(['barcode' => '', 'cat_number' => ''])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $record = Record::where('title', 'Test Record')->first();
        $this->assertNotNull($record);
        $this->assertEquals('RAD'.$record->id, $record->barcode);
        $this->assertEmpty($record->cat_number);
    }

    // --- A2: Create with only barcode filled ---

    public function test_create_record_with_barcode_only_keeps_barcode(): void
    {
        $response = $this->actingAs($this->user)->post(
            route('record.store'),
            $this->baseRecordData(['barcode' => '1234567890', 'cat_number' => ''])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $record = Record::where('title', 'Test Record')->first();
        $this->assertEquals('1234567890', $record->barcode);
        $this->assertEmpty($record->cat_number);
    }

    // --- A3: Create with only cat_number filled ---

    public function test_create_record_with_cat_number_only_keeps_cat_number(): void
    {
        $response = $this->actingAs($this->user)->post(
            route('record.store'),
            $this->baseRecordData(['barcode' => '', 'cat_number' => 'ABC-001'])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $record = Record::where('title', 'Test Record')->first();
        $this->assertEmpty($record->barcode);
        $this->assertEquals('ABC-001', $record->cat_number);
    }

    // --- A4: Create with duplicate barcode shows warning ---

    public function test_create_record_with_duplicate_barcode_shows_warning(): void
    {
        // Create existing record with a barcode
        Record::factory()->create([
            'barcode' => '9999999999',
            'format_id' => $this->format->id,
        ]);

        $response = $this->actingAs($this->user)->post(
            route('record.store'),
            $this->baseRecordData(['barcode' => '9999999999', 'cat_number' => 'UNIQUE-CAT'])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Record should be created (warning, not blocking)
        $this->assertEquals(2, Record::where('barcode', '9999999999')->count());

        // Warning should be flashed
        $response->assertSessionHas('warning');
        $warning = session('warning');
        $this->assertStringContainsString('9999999999', $warning);
        $this->assertStringContainsString('Vedi duplicati', $warning);
    }

    // --- A5: Create with duplicate cat_number shows warning ---

    public function test_create_record_with_duplicate_cat_number_shows_warning(): void
    {
        Record::factory()->create([
            'cat_number' => 'DUPE-CAT',
            'format_id' => $this->format->id,
        ]);

        $response = $this->actingAs($this->user)->post(
            route('record.store'),
            $this->baseRecordData(['barcode' => '1111111111', 'cat_number' => 'DUPE-CAT'])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertEquals(2, Record::where('cat_number', 'DUPE-CAT')->count());

        $response->assertSessionHas('warning');
        $warning = session('warning');
        $this->assertStringContainsString('DUPE-CAT', $warning);
    }

    // --- B2: Edit and change barcode to duplicate shows warning ---

    public function test_update_record_with_duplicate_barcode_shows_warning(): void
    {
        $existing = Record::factory()->create([
            'barcode' => '8888888888',
            'format_id' => $this->format->id,
        ]);

        $record = Record::factory()->create([
            'barcode' => '7777777777',
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);

        $response = $this->actingAs($this->user)->put(
            route('record.update', $record),
            $this->baseRecordData([
                'barcode' => '8888888888', // Change to duplicate
                'cat_number' => $record->cat_number,
            ])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $response->assertSessionHas('warning');
        $warning = session('warning');
        $this->assertStringContainsString('8888888888', $warning);
    }

    // --- B3: Edit and change cat_number to duplicate shows warning ---

    public function test_update_record_with_duplicate_cat_number_shows_warning(): void
    {
        $existing = Record::factory()->create([
            'cat_number' => 'EXISTING-CAT',
            'format_id' => $this->format->id,
        ]);

        $record = Record::factory()->create([
            'cat_number' => 'ORIGINAL-CAT',
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);

        $response = $this->actingAs($this->user)->put(
            route('record.update', $record),
            $this->baseRecordData([
                'barcode' => $record->barcode,
                'cat_number' => 'EXISTING-CAT',
            ])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $response->assertSessionHas('warning');
        $warning = session('warning');
        $this->assertStringContainsString('EXISTING-CAT', $warning);
    }

    // --- No warning when barcode/cat_number are unique ---

    public function test_create_record_with_unique_barcode_no_warning(): void
    {
        $response = $this->actingAs($this->user)->post(
            route('record.store'),
            $this->baseRecordData(['barcode' => '5555555555', 'cat_number' => 'UNIQUE-1'])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionMissing('warning');
    }

    // --- Barcode is nullable (no validation error) ---

    public function test_barcode_is_nullable(): void
    {
        $response = $this->actingAs($this->user)->post(
            route('record.store'),
            $this->baseRecordData(['barcode' => null, 'cat_number' => 'HAS-CAT'])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    // --- Cat number is nullable (no validation error) ---

    public function test_cat_number_is_nullable(): void
    {
        $response = $this->actingAs($this->user)->post(
            route('record.store'),
            $this->baseRecordData(['barcode' => '6666666666', 'cat_number' => null])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }
}
