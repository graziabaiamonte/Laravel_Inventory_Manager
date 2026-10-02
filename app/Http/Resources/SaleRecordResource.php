<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'record_id' => $this->record_id,
            'stock_id' => $this->stock_id,
            'stock' => $this->stock,
            'quantity' => $this->quantity,
            'price' => $this->price?->formatByDecimal(),
            'vat' => $this->vat,
            'discount' => $this->discount,
            'total_price' => $this->total_price?->formatByDecimal(),
            'total_price_formatted' => $this->total_price ? '€ '.number_format($this->total_price->getAmount() / 100, 2, ',', '.') : null,
            'supplier_name' => $this->record?->wholesaleInRecords?->first()?->wholesaleIn?->supplier?->name ?? null,
            'parent_record' => new RecordResource($this->whenLoaded('record', function () {
                return $this->record->loadMissing('stocks.area.locations');
            })),
        ];
    }
}
