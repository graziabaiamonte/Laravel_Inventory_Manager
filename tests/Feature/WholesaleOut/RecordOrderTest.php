<?php

namespace Tests\Feature\WholesaleOut;

use App\Models\Record;
use App\Models\Stock;
use App\Models\WholesaleOut;
use Inertia\Testing\AssertableInertia as Assert;

class RecordOrderTest extends WholesaleOutTestCase
{
    private function updatePayload(WholesaleOut $wo, array $records): array
    {
        return [
            '_method' => 'PUT',
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'doc_num' => $wo->doc_num,
            'status' => 0, // keep draft (no stock side effects)
            'records' => $records,
        ];
    }

    private function lineItem(Record $record, int $qty = 1, ?int $id = null): array
    {
        $stock = Stock::where('record_id', $record->id)->where('area_id', $this->area1->id)->first();
        $li = [
            'record_id' => $record->id,
            'stock_id' => $stock->id,
            'quantity' => $qty,
            'unit_price' => 10.00,
            'discount' => 0,
            'total_price' => 10.00 * $qty,
            'vat' => 22,
            'area_quantities' => [
                ['area_id' => $this->area1->id, 'quantity' => $qty],
            ],
        ];
        if ($id !== null) {
            $li['id'] = $id; // existing line item
        }

        return $li;
    }

    public function test_records_reload_in_add_order_not_id_order(): void
    {
        $wo = WholesaleOut::factory()->create([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area1->id,
            'status' => 0,
        ]);

        // Phase 1: submit record2 FIRST (as if it was the most recently added), then record1.
        // record2's line item is created first -> gets the LOWER id, but position 0.
        $this->actingAs($this->user)
            ->post(route('wholesale-out.update', $wo), $this->updatePayload($wo, [
                $this->lineItem($this->record2),
                $this->lineItem($this->record1),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Reload must preserve the submitted (add) order: record2 first, record1 second.
        $this->actingAs($this->user)->get(route('wholesale-out.edit', $wo))
            ->assertInertia(fn (Assert $page) => $page
                ->component('WholesaleOut/Edit')
                ->has('wholesaleOut.records', 2)
                ->where('wholesaleOut.records.0.record_id', $this->record2->id)
                ->where('wholesaleOut.records.1.record_id', $this->record1->id)
            );

        $wo->refresh();
        $li2 = $wo->records()->where('record_id', $this->record2->id)->first();
        $li1 = $wo->records()->where('record_id', $this->record1->id)->first();

        // Phase 2: add a brand-new record on top. It gets the HIGHEST id (auto-increment),
        // yet must still load FIRST because its position is 0.
        // This is exactly what plain id ASC/DESC ordering could not achieve.
        $record3 = Record::factory()->create([
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
        ]);
        Stock::create(['record_id' => $record3->id, 'area_id' => $this->area1->id, 'quantity' => 50]);

        $this->actingAs($this->user)
            ->post(route('wholesale-out.update', $wo), $this->updatePayload($wo, [
                $this->lineItem($record3),                          // newest -> position 0, highest id
                $this->lineItem($this->record2, 1, $li2->id),       // existing
                $this->lineItem($this->record1, 1, $li1->id),       // existing
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $newLi = $wo->fresh()->records()->where('record_id', $record3->id)->first();
        $this->assertGreaterThan($li1->id, $newLi->id, 'Sanity: the newly added record has the highest id');

        $this->actingAs($this->user)->get(route('wholesale-out.edit', $wo))
            ->assertInertia(fn (Assert $page) => $page
                ->component('WholesaleOut/Edit')
                ->has('wholesaleOut.records', 3)
                ->where('wholesaleOut.records.0.record_id', $record3->id)   // highest id, still first
                ->where('wholesaleOut.records.1.record_id', $this->record2->id)
                ->where('wholesaleOut.records.2.record_id', $this->record1->id)
            );
    }
}
