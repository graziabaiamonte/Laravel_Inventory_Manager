<?php

namespace Tests\Feature;

use App\Enums\RolesEnum;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PerPageCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_page_is_capped(): void
    {
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(RolesEnum::Admin->value);
        Artist::factory()->create();

        $this->actingAs($admin)
            ->get(route('artist.index', ['per_page' => 1000000]))
            ->assertInertia(fn (Assert $page) => $page->where('artists.per_page', 500));
    }
}
