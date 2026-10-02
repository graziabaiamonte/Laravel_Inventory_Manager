<?php

namespace Tests\Feature;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Area;
use App\Models\Location;
use App\Models\User;
use App\Models\WholesaleIn;
use App\Models\WholesaleOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Location-scoped users can only open, export or delete the wholesale documents
 * of the areas connected to their locations, matching what the index lists.
 */
class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Area $ownArea;

    private Area $foreignArea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->ownArea = Area::factory()->create(['name' => 'Own Area']);
        $this->foreignArea = Area::factory()->create(['name' => 'Foreign Area']);

        $ownLocation = Location::factory()->create(['type' => LocationTypeEnum::WAREHOUSE, 'status' => 1]);
        $ownLocation->areas()->attach($this->ownArea->id);
        $foreignLocation = Location::factory()->create(['type' => LocationTypeEnum::WAREHOUSE, 'status' => 1]);
        $foreignLocation->areas()->attach($this->foreignArea->id);

        $this->operator = User::factory()->create();
        $this->operator->assignRole(RolesEnum::Operator->value);
        $this->operator->locations()->attach($ownLocation->id);
    }

    public function test_a_foreign_wholesale_out_cannot_be_opened_exported_or_deleted(): void
    {
        $foreign = WholesaleOut::factory()->create(['area_id' => $this->foreignArea->id]);

        $this->actingAs($this->operator)->get(route('wholesale-out.edit', $foreign))->assertForbidden();
        $this->actingAs($this->operator)->get(route('wholesale-out.export', $foreign))->assertForbidden();
        $this->actingAs($this->operator)->delete(route('wholesale-out.destroy', $foreign))->assertForbidden();

        $this->assertModelExists($foreign);
    }

    public function test_a_foreign_wholesale_in_cannot_be_opened_exported_printed_or_deleted(): void
    {
        $foreign = WholesaleIn::factory()->create(['area_id' => $this->foreignArea->id]);

        $this->actingAs($this->operator)->get(route('wholesale-in.edit', $foreign))->assertForbidden();
        $this->actingAs($this->operator)->get(route('wholesale-in.export', $foreign))->assertForbidden();
        $this->actingAs($this->operator)->get(route('wholesale-in.barcodes', $foreign))->assertForbidden();
        $this->actingAs($this->operator)->delete(route('wholesale-in.destroy', $foreign))->assertForbidden();

        $this->assertModelExists($foreign);
    }

    public function test_bulk_delete_skips_foreign_documents(): void
    {
        $ownOut = WholesaleOut::factory()->create(['area_id' => $this->ownArea->id]);
        $foreignOut = WholesaleOut::factory()->create(['area_id' => $this->foreignArea->id]);
        $ownIn = WholesaleIn::factory()->create(['area_id' => $this->ownArea->id]);
        $foreignIn = WholesaleIn::factory()->create(['area_id' => $this->foreignArea->id]);

        $this->actingAs($this->operator)
            ->delete(route('wholesale-out.destroy', ['wholesale_out' => $ownOut->id]), ['ids' => [$ownOut->id, $foreignOut->id]]);
        $this->actingAs($this->operator)
            ->delete(route('wholesale-in.destroy', ['wholesale_in' => $ownIn->id]), ['ids' => [$ownIn->id, $foreignIn->id]]);

        $this->assertModelMissing($ownOut);
        $this->assertModelExists($foreignOut);
        $this->assertModelMissing($ownIn);
        $this->assertModelExists($foreignIn);
    }

    public function test_own_documents_stay_accessible(): void
    {
        $out = WholesaleOut::factory()->create(['area_id' => $this->ownArea->id]);
        $in = WholesaleIn::factory()->create(['area_id' => $this->ownArea->id]);

        $this->actingAs($this->operator)->get(route('wholesale-out.edit', $out))->assertOk();
        $this->actingAs($this->operator)->get(route('wholesale-out.export', $out))->assertOk();
        $this->actingAs($this->operator)->get(route('wholesale-in.edit', $in))->assertOk();
        $this->actingAs($this->operator)->get(route('wholesale-in.export', $in))->assertOk();
    }
}
