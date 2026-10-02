<?php

namespace Tests\Feature\WholesaleIn;

use App\Models\Record;
use App\Models\WholesaleIn;
use Inertia\Testing\AssertableInertia as Assert;

class RecordOrderTest extends WholesaleInTestCase
{
    private function lineItem(Record $record, int $qty = 1): array
    {
        return [
            'record_id' => $record->id,
            'quantity' => $qty,
            'unit_price' => 10.00,
            'discount' => 0,
            'total_price' => 10.00 * $qty,
            'vat' => 22,
            'area_quantities' => [
                ['area_id' => $this->area1->id, 'quantity' => $qty],
            ],
        ];
    }

    private function save(WholesaleIn $wi, array $records): void
    {
        $this->actingAs($this->user)
            ->patch(route('wholesale-in.update', $wi), [
                'supplier_id' => $this->supplier->id,
                'area_id' => $this->area1->id,
                'doc_num' => $wi->doc_num,
                'description' => 'Test',
                'status' => 0, // draft, no stock side effects
                'records' => $records,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_records_reload_in_submitted_add_order(): void
    {
        $wi = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
            'doc_num' => 'WI-ORDER',
        ]);

        // Submit record2 first, then record1.
        $this->save($wi, [$this->lineItem($this->record2), $this->lineItem($this->record1)]);

        $this->actingAs($this->user)->get(route('wholesale-in.edit', $wi))
            ->assertInertia(fn (Assert $page) => $page
                ->component('WholesaleIn/Edit')
                ->has('wholesaleIn.records', 2)
                ->where('wholesaleIn.records.0.record_id', $this->record2->id)
                ->where('wholesaleIn.records.1.record_id', $this->record1->id)
            );

        // Re-save in the opposite order: the reload order must follow the new add order.
        $this->save($wi, [$this->lineItem($this->record1), $this->lineItem($this->record2)]);

        $this->actingAs($this->user)->get(route('wholesale-in.edit', $wi))
            ->assertInertia(fn (Assert $page) => $page
                ->component('WholesaleIn/Edit')
                ->has('wholesaleIn.records', 2)
                ->where('wholesaleIn.records.0.record_id', $this->record1->id)
                ->where('wholesaleIn.records.1.record_id', $this->record2->id)
            );
    }
}
