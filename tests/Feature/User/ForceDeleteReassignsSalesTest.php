<?php

namespace Tests\Feature\User;

use App\Enums\LocationTypeEnum;
use App\Enums\RolesEnum;
use App\Models\Area;
use App\Models\Location;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permanently deleting a user must not take their sales with them.
 *
 * sales.user_id is ON DELETE CASCADE and nothing in the schema restricts it, so
 * the old force delete silently destroyed every sale the account had recorded,
 * and their sale_records through the sales.
 */
class ForceDeleteReassignsSalesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->location = Location::factory()->create([
            'type' => LocationTypeEnum::STORE,
            'status' => 1,
            'default_area_id' => Area::factory()->create()->id,
        ]);

        $this->admin = User::factory()->create(['email' => 'capo@'.User::COMPANY_EMAIL_DOMAIN]);
        $this->admin->assignRole(RolesEnum::Admin->value);
    }

    private function trashedUserWithSales(int $count, string $role = RolesEnum::OPERATOR): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        Sale::factory()->count($count)->create([
            'user_id' => $user->id,
            'location_id' => $this->location->id,
        ]);

        $user->delete();

        return $user->fresh();
    }

    public function test_sales_are_handed_to_an_administrator_instead_of_being_destroyed(): void
    {
        $doomed = $this->trashedUserWithSales(3);
        $saleIds = Sale::where('user_id', $doomed->id)->pluck('id')->all();

        $response = $this->actingAs($this->admin)
            ->delete(route('user.force-destroy', $doomed->id));

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $doomed->id]);
        $this->assertCount(3, $saleIds, 'Sanity: the user had sales to hand over');

        foreach ($saleIds as $id) {
            $this->assertDatabaseHas('sales', ['id' => $id, 'user_id' => $this->admin->id]);
        }
    }

    public function test_the_lowest_id_administrator_on_the_company_domain_is_chosen(): void
    {
        $laterCompanyAdmin = User::factory()->create(['email' => 'secondo@'.User::COMPANY_EMAIL_DOMAIN]);
        $laterCompanyAdmin->assignRole(RolesEnum::Admin->value);

        $doomed = $this->trashedUserWithSales(1);
        $saleId = Sale::where('user_id', $doomed->id)->value('id');

        $this->actingAs($this->admin)
            ->delete(route('user.force-destroy', $doomed->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'user_id' => $this->admin->id]);
        $this->assertLessThan($laterCompanyAdmin->id, $this->admin->id, 'Sanity: the chosen admin is the lower id');
    }

    /**
     * Administrators may exist on other domains. A lower id must not put the
     * shop's sales there.
     */
    public function test_an_administrator_outside_the_company_domain_is_skipped(): void
    {
        $this->admin->forceDelete(); // remove the company admin created in setUp

        $agencyAdmin = User::factory()->create(['email' => 'support@atomicastudio.com']);
        $agencyAdmin->assignRole(RolesEnum::Admin->value);

        $companyAdmin = User::factory()->create(['email' => 'interno@'.User::COMPANY_EMAIL_DOMAIN]);
        $companyAdmin->assignRole(RolesEnum::Admin->value);

        $this->assertLessThan($companyAdmin->id, $agencyAdmin->id, 'Sanity: the outside admin has the lower id');

        $doomed = $this->trashedUserWithSales(1);
        $saleId = Sale::where('user_id', $doomed->id)->value('id');

        $this->actingAs($companyAdmin)
            ->delete(route('user.force-destroy', $doomed->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'user_id' => $companyAdmin->id]);
    }

    public function test_a_user_without_sales_is_deleted_normally(): void
    {
        $doomed = $this->trashedUserWithSales(0);

        $this->actingAs($this->admin)
            ->delete(route('user.force-destroy', $doomed->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $doomed->id]);
    }

    public function test_the_delete_is_refused_when_no_other_administrator_remains(): void
    {
        // The only administrator is the one being deleted.
        $soleAdmin = User::factory()->create(['email' => 'solo@'.User::COMPANY_EMAIL_DOMAIN]);
        $soleAdmin->assignRole(RolesEnum::Admin->value);
        Sale::factory()->create(['user_id' => $soleAdmin->id, 'location_id' => $this->location->id]);
        $soleAdmin->delete();

        $this->admin->forceDelete(); // remove the other admin from the picture

        $response = $this->actingAs($soleAdmin)
            ->delete(route('user.force-destroy', $soleAdmin->id));

        $response->assertSessionHasErrors();

        $this->assertTrue(
            User::withTrashed()->whereKey($soleAdmin->id)->exists(),
            'The user must survive a refused permanent delete'
        );
        $this->assertDatabaseHas('sales', ['user_id' => $soleAdmin->id]);
    }

    public function test_the_delete_is_refused_when_only_outside_administrators_remain(): void
    {
        $this->admin->forceDelete();

        $agencyAdmin = User::factory()->create(['email' => 'support@atomicastudio.com']);
        $agencyAdmin->assignRole(RolesEnum::Admin->value);

        $doomed = $this->trashedUserWithSales(1);

        $response = $this->actingAs($agencyAdmin)
            ->delete(route('user.force-destroy', $doomed->id));

        // Refusing loses nothing; the cascade would lose every sale.
        $response->assertSessionHasErrors();
        $this->assertTrue(User::withTrashed()->whereKey($doomed->id)->exists());
        $this->assertDatabaseHas('sales', ['user_id' => $doomed->id]);
    }

    public function test_a_bulk_permanent_delete_reassigns_every_user_sales(): void
    {
        $first = $this->trashedUserWithSales(2);
        $second = $this->trashedUserWithSales(1);
        $saleIds = Sale::whereIn('user_id', [$first->id, $second->id])->pluck('id')->all();

        $this->actingAs($this->admin)
            ->delete(route('user.force-destroy', $first->id), [
                'ids' => [$first->id, $second->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $first->id]);
        $this->assertDatabaseMissing('users', ['id' => $second->id]);

        $this->assertCount(3, $saleIds);
        foreach ($saleIds as $id) {
            $this->assertDatabaseHas('sales', ['id' => $id, 'user_id' => $this->admin->id]);
        }
    }

    public function test_a_trashed_administrator_is_not_treated_as_available(): void
    {
        $trashedAdmin = User::factory()->create(['email' => 'cestinato@'.User::COMPANY_EMAIL_DOMAIN]);
        $trashedAdmin->assignRole(RolesEnum::Admin->value);
        $trashedAdmin->delete();

        $doomed = $this->trashedUserWithSales(1);
        $saleId = Sale::where('user_id', $doomed->id)->value('id');

        $this->actingAs($this->admin)
            ->delete(route('user.force-destroy', $doomed->id))
            ->assertSessionHasNoErrors();

        // Handed to the live admin, never to the trashed one.
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'user_id' => $this->admin->id]);
    }
}
