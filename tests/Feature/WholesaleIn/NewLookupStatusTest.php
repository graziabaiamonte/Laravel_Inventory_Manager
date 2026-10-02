<?php

namespace Tests\Feature\WholesaleIn;

use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use Illuminate\Support\Facades\Http;

/**
 * Formats, labels and artists first created by a wholesale in must be active,
 * otherwise they are missing from the record create and edit dropdowns.
 */
class NewLookupStatusTest extends WholesaleInTestCase
{
    public function test_new_format_label_and_artist_are_created_active(): void
    {
        Http::fake();

        $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'LOOKUP-1',
            'description' => 'Test',
            'status' => 0,
            'records' => [
                [
                    'record_id' => 0,
                    'cat_number' => 'LOOKUP-CAT-1',
                    'title' => 'Lookup Rec',
                    'format' => 'Brand New Format',
                    'label' => 'Brand New Label',
                    'artist' => 'Brand New Artist',
                    'quantity' => 1,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 10.00,
                    'vat' => 22,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 1],
                    ],
                ],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, (int) Format::where('name', 'Brand New Format')->firstOrFail()->status);
        $this->assertSame(1, (int) Label::where('name', 'Brand New Label')->firstOrFail()->status);
        $this->assertSame(1, (int) Artist::where('name', 'Brand New Artist')->firstOrFail()->status);
    }
}
