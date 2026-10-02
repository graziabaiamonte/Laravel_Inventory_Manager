<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackorderRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Calculate total available quantity (sum of all stocks for this record)
        $totalAvailableQuantity = 0;
        if ($this->wholesaleOutRecord && $this->wholesaleOutRecord->parentRecord) {
            $totalAvailableQuantity = $this->wholesaleOutRecord->parentRecord->stocks()->sum('quantity');
        }

        return [
            'id' => $this->id,
            'backorder_id' => $this->backorder_id,
            'wholesale_out_record_id' => $this->wholesale_out_record_id,
            'quantity' => $this->quantity,

            // Calculated quantities (same as WholesaleOutRecord)
            'shipped_quantity' => $this->shipped_quantity,
            'backordered_quantity' => $this->backordered_quantity,

            'parent_record' => $this->whenLoaded('wholesaleOutRecord', function () {
                return new RecordResource($this->wholesaleOutRecord->parentRecord);
            }),

            // Informazioni del record tramite wholesaleOutRecord
            'cat_number' => $this->wholesaleOutRecord->parentRecord->cat_number ?? null,
            'barcode' => $this->wholesaleOutRecord->parentRecord->barcode ?? null,
            'artist' => $this->wholesaleOutRecord->parentRecord->artist->name ?? null,
            'title' => $this->wholesaleOutRecord->parentRecord->title ?? null,
            'format' => $this->wholesaleOutRecord->parentRecord->format->name ?? null,
            'label' => $this->wholesaleOutRecord->parentRecord->label->name ?? null,
            'unit_price' => $this->wholesaleOutRecord->unit_price ? money($this->wholesaleOutRecord->unit_price)->formatByDecimal() : null,
            'discount' => $this->wholesaleOutRecord->discount ?? null,
            'total_price' => $this->wholesaleOutRecord->total_price ? money($this->wholesaleOutRecord->total_price)->formatByDecimal() : null,
            'vat' => $this->wholesaleOutRecord->vat ?? null,

            // Informazioni aggiuntive
            'stock_id' => $this->wholesaleOutRecord->stock_id ?? null,
            'available_stock' => $this->wholesaleOutRecord->stock->quantity ?? null,
            'available_quantity' => $totalAvailableQuantity, // Total quantity available across all areas/stocks

            // Aree del backorder record
            'area_quantities' => $this->backorderRecordsAreas->map(function ($areaRecord) {
                return [
                    'id' => $areaRecord->id,
                    'area_id' => $areaRecord->area_id,
                    'area_name' => $areaRecord->area->name ?? null,
                    'quantity' => $areaRecord->quantity,
                ];
            })->toArray(),

            'created_at' => $this->created_at->format('d/m/Y H:i'),
        ];
    }
}
