<?php

namespace Tests\Feature\Sale;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Jobs\ExportSalesJob;
use App\Models\Location;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The export jobs run without an authenticated user, so the location scope the
 * index applies through filterByAdminRoles() has to be passed to them explicitly.
 */
class ExportScopeTest extends TestCase
{
    use RefreshDatabase;

    private Location $ownLocation;

    private Location $foreignLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Storage::fake('local');
        Queue::fake();

        $this->ownLocation = Location::factory()->create(['type' => LocationTypeEnum::STORE, 'status' => 1]);
        $this->foreignLocation = Location::factory()->create(['type' => LocationTypeEnum::STORE, 'status' => 1]);

        $seller = User::factory()->create();
        Sale::factory()->count(2)->create(['user_id' => $seller->id, 'location_id' => $this->ownLocation->id]);
        Sale::factory()->count(3)->create(['user_id' => $seller->id, 'location_id' => $this->foreignLocation->id]);
    }

    private function exportTotal(User $user): int
    {
        $exportId = $this->actingAs($user)->getJson(route('sale.export'))->assertOk()->json('export_id');

        return json_decode(Storage::disk('local')->get("exports/{$exportId}/progress.json"), true)['total_records'];
    }

    public function test_an_operator_exports_only_the_sales_of_their_locations(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole(RolesEnum::Operator->value);
        $operator->locations()->attach($this->ownLocation->id);

        $this->assertSame(2, $this->exportTotal($operator));

        Queue::assertPushed(ExportSalesJob::class, function (ExportSalesJob $job) {
            $scope = (fn () => $this->allowedLocationIds)->call($job);

            return $scope === [$this->ownLocation->id];
        });
    }

    public function test_an_admin_exports_every_sale(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolesEnum::Admin->value);

        $this->assertSame(5, $this->exportTotal($admin));
    }
}
