<?php

namespace Tests\Feature\Record;

use App\Enums\RolesEnum;
use App\Models\Artist;
use App\Models\Customer;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A filter value containing a comma used to 500 the index pages, because
 * spatie/laravel-query-builder split it into an array and every filter callback
 * assumed a string. Commas are ordinary content in record titles and customer
 * names, so these cover both the raw-concatenation filters and the shared
 * SearchFilter helper, plus the `|` multi-token convention that must keep working.
 */
class FilterCommaTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Record $commaTitled;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(RolesEnum::Admin->value);

        $artist = Artist::factory()->create(['name' => 'New Order']);
        $format = Format::factory()->create();
        $label = Label::factory()->create();

        $this->commaTitled = Record::factory()->create([
            'title' => 'Power, Corruption & Lies',
            'artist_id' => $artist->id,
            'format_id' => $format->id,
            'label_id' => $label->id,
        ]);

        Record::factory()->create([
            'title' => 'Unrelated Album',
            'artist_id' => $artist->id,
            'format_id' => $format->id,
            'label_id' => $label->id,
        ]);
    }

    public function test_the_title_filter_accepts_a_comma(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/record?filter[title]=Power, Corruption');

        $response->assertOk();
        $this->assertTitlesReturned($response, ['Power, Corruption & Lies']);
    }

    public function test_the_search_filter_accepts_a_comma(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/record?filter[search]=Power, Corruption');

        $response->assertOk();
        $this->assertTitlesReturned($response, ['Power, Corruption & Lies']);
    }

    public function test_the_search_filter_still_splits_on_a_pipe(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/record?filter[search]=Power|Lies');

        $response->assertOk();
        $this->assertTitlesReturned($response, ['Power, Corruption & Lies']);
    }

    public function test_a_pipe_search_requires_every_token_to_match(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/record?filter[search]=Power|Nonexistent');

        $response->assertOk();
        $this->assertTitlesReturned($response, []);
    }

    public function test_the_autocomplete_endpoint_accepts_a_comma(): void
    {
        // Autocomplete request with a comma in the title filter
        $response = $this->actingAs($this->user)
            ->getJson('/record?filter[title]=Power, C&orderByTotalStock=1');

        $response->assertOk();
        $this->assertSame(
            ['Power, Corruption & Lies'],
            collect($response->json())->pluck('title')->all()
        );
    }

    public function test_the_autocomplete_artist_filter_accepts_a_comma(): void
    {
        Artist::factory()->create(['name' => 'Emerson, Lake & Palmer']);

        $response = $this->actingAs($this->user)
            ->getJson('/record?filter[artist]=Emerson, Lake');

        $response->assertOk();
    }

    public function test_an_array_shaped_filter_value_does_not_crash(): void
    {
        $this->actingAs($this->user)
            ->get('/record?filter[title][]=Power&filter[title][]=Lies')
            ->assertOk();

        $this->actingAs($this->user)
            ->get('/record?filter[search][]=Power&filter[search][]=Lies')
            ->assertOk();
    }

    public function test_the_shared_helper_accepts_a_comma_on_another_index(): void
    {
        Customer::factory()->create(['name' => 'Wayne, Jr.', 'last_name' => 'Coyne']);

        $response = $this->actingAs($this->user)
            ->get('/customer?filter[search]=Wayne, Jr.');

        $response->assertOk();
    }

    /**
     * Assert the exact set of record titles the index page returned.
     *
     * @param  array<int, string>  $expected
     */
    private function assertTitlesReturned($response, array $expected): void
    {
        $titles = collect($response->viewData('page')['props']['records']['data'])
            ->pluck('title')
            ->sort()
            ->values()
            ->all();

        sort($expected);

        $this->assertSame($expected, $titles);
    }
}
