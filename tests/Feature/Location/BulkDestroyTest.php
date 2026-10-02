<?php

namespace Tests\Feature\Location;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_id_in_a_bulk_delete_is_skipped(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(RolesEnum::Admin->value);

        $location = Location::factory()->create(['type' => LocationTypeEnum::STORE, 'status' => 1]);

        $this->actingAs($admin)
            ->delete(route('location.destroy', ['location' => $location->id]), ['ids' => [999999, $location->id]])
            ->assertRedirect();

        $this->assertSoftDeleted($location);
    }
}
