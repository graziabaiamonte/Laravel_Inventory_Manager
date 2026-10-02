<?php

namespace Tests\Feature\Discogs;

use App\Services\External\DiscogsClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Discogs search results can come without uri or barcode keys; they must be
 * mapped to empty values rather than failing the whole search.
 */
class SearchResultTest extends TestCase
{
    public function test_results_without_uri_or_barcode_are_still_returned(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['id' => 1, 'title' => 'No Uri Release'],
            ['id' => 2, 'title' => 'Full Release', 'uri' => '/release/2', 'barcode' => ['80 1234-5678']],
        ]])]);

        $result = app(DiscogsClient::class)->search(null, 'CAT-1');

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['data'][0]['uri']);
        $this->assertSame('', $result['data'][0]['barcode']);
        $this->assertSame('https://www.discogs.com/release/2', $result['data'][1]['uri']);
        $this->assertSame('8012345678', $result['data'][1]['barcode']);
    }
}
