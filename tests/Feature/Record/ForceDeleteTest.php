<?php

namespace Tests\Feature\Record;

use App\Enums\RolesEnum;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Customer;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use App\Models\Stock;
use App\Models\User;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers permanently deleting a record from the trash.
 *
 * Everything hanging off a record cascades away with it by design: the UI warns
 * rather than the schema refusing. The one thing the database does refuse is
 * wholesale_out_records.record_id (ON DELETE RESTRICT), which used to surface
 * as an unhandled 1451.
 */
class ForceDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(RolesEnum::Admin->value);

        Format::factory()->create();
        Label::factory()->create();
        Artist::factory()->create();
    }

    private function trashedRecord(): Record
    {
        $record = Record::factory()->create();
        $record->delete();

        return $record->fresh();
    }

    private function wholesaleOutLineFor(Record $record): WholesaleOutRecord
    {
        return WholesaleOutRecord::factory()->create([
            'wholesale_out_id' => WholesaleOut::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
            ])->id,
            'record_id' => $record->id,
            'stock_id' => null,
        ]);
    }

    public function test_a_record_in_a_wholesale_out_is_refused_instead_of_erroring(): void
    {
        $record = $this->trashedRecord();
        $line = $this->wholesaleOutLineFor($record);

        $response = $this->actingAs($this->user)
            ->delete(route('record.force-destroy', $record->id));

        $response->assertSessionHasErrors();

        $this->assertTrue(
            Record::withTrashed()->whereKey($record->id)->exists(),
            'The record must survive a refused permanent delete'
        );
        $this->assertDatabaseHas('wholesale_out_records', ['id' => $line->id]);
    }

    public function test_a_record_with_only_cascading_relations_is_still_deletable(): void
    {
        $record = $this->trashedRecord();
        $stock = Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => Area::factory()->create()->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('record.force-destroy', $record->id));

        $response->assertSessionHasNoErrors();

        // Permanent deletion must stay possible; the warning in
        // the trash modal is what makes the cascade visible before the fact.
        $this->assertDatabaseMissing('records', ['id' => $record->id]);
        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    /**
     * The bulk branch used to clear for_sale_on_discogs on the route-bound
     * record rather than the one being iterated, so only that one was cleared
     * however many were in the batch, and a null route binding would have been
     * a fatal.
     */
    public function test_a_bulk_soft_delete_clears_the_discogs_flag_on_every_record(): void
    {
        $first = Record::factory()->create(['for_sale_on_discogs' => 1]);
        $second = Record::factory()->create(['for_sale_on_discogs' => 1]);

        $response = $this->actingAs($this->user)
            ->delete(route('record.destroy', $first->id), [
                'ids' => [$first->id, $second->id],
            ]);

        $response->assertSessionHasNoErrors();

        foreach ([$first, $second] as $record) {
            $fresh = Record::withTrashed()->findOrFail($record->id);
            $this->assertTrue($fresh->trashed(), "Record {$record->id} should be in the trash");
            $this->assertEquals(0, $fresh->for_sale_on_discogs, "Record {$record->id} should be delisted");
        }
    }

    public function test_a_bulk_delete_reports_the_blocked_records_and_removes_the_rest(): void
    {
        $blocked = $this->trashedRecord();
        $this->wholesaleOutLineFor($blocked);
        $deletable = $this->trashedRecord();

        $response = $this->actingAs($this->user)
            ->delete(route('record.force-destroy', $blocked->id), [
                'ids' => [$blocked->id, $deletable->id],
            ]);

        $response->assertSessionHasErrors();

        $this->assertTrue(Record::withTrashed()->whereKey($blocked->id)->exists());
        $this->assertDatabaseMissing('records', ['id' => $deletable->id]);
    }
}
