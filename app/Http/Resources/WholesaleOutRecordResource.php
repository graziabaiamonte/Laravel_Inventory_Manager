<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WholesaleOutRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Determine available stock at the time of loading, if stock relation is loaded
        $availableStock = null;
        if ($this->relationLoaded('stock') && $this->stock) {
            $availableStock = $this->stock->quantity;
        } elseif ($this->relationLoaded('parentRecord') && $this->parentRecord && $this->wholesaleOut && $this->wholesaleOut->relationLoaded('area')) {
            // Attempt to get current stock if not directly loaded on the record
            // $this->parentRecord is an instance of App\Models\Record
            $stockInfo = $this->parentRecord->stocks()->where('area_id', $this->wholesaleOut->area_id)->first();
            $availableStock = $stockInfo ? $stockInfo->quantity : 0;
        }

        // Sums precomputed by the caller via withSum on parentRecord.
        // Match legacy sum() output: string when non-zero, int 0 when zero/null.
        $totalAvailableQuantity = $this->parentRecord?->total_stocks
            ? (string) $this->parentRecord->total_stocks
            : 0;
        $warehouseStockQuantity = $this->parentRecord?->total_warehouse_stocks
            ? (string) $this->parentRecord->total_warehouse_stocks
            : 0;

        // Prepare area quantities if the relationship is loaded
        $areaQuantities = [];
        if ($this->relationLoaded('wholesaleOutRecordsArea')) {
            foreach ($this->wholesaleOutRecordsArea as $areaRecord) {
                $areaQuantities[] = [
                    'area_id' => $areaRecord->area_id,
                    'area_name' => $areaRecord->area?->name ?? null,
                    'quantity' => $areaRecord->quantity,
                ];
            }
        }

        // If area_quantities is empty but we have a stock_id, populate it from the stock's area
        // This ensures draft WholesaleOuts have area_quantities for editing
        if (empty($areaQuantities) && $this->stock_id && $this->relationLoaded('stock') && $this->stock) {
            $areaQuantities[] = [
                'area_id' => $this->stock->area_id,
                'area_name' => $this->stock->area?->name ?? null,
                'quantity' => $this->quantity,
            ];
        }

        return [
            'id' => $this->id,
            'wholesale_out_id' => $this->wholesale_out_id,
            'record_id' => $this->record_id,
            'stock_id' => $this->stock_id,
            'quantity' => $this->quantity,
            'unit_price' => money($this->unit_price)->formatByDecimal(),
            'discount' => $this->discount,
            'total_price' => money($this->total_price)->formatByDecimal(),
            'vat' => $this->vat,

            // Eager load 'parentRecord' with its own relations (artist, format, label) in controller
            'parent_record' => new RecordResource($this->whenLoaded('parentRecord')),

            // Denormalized fields for convenience in UI, from the related Record
            'cat_number' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->cat_number),
            'barcode' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->barcode),
            'artist' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->artist?->name),
            'title' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->title),
            'format' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->format?->name),
            'label' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->label?->name),
            'available_stock' => $availableStock, // Current stock quantity for this item at its stock location
            'available_quantity' => $totalAvailableQuantity, // Total quantity available across all areas/stocks
            'warehouse_stock_quantity' => $warehouseStockQuantity, // Total quantity available in warehouse areas only
            'shipped_quantity' => $this->shipped_quantity, // Calculated: quantity - backordered
            'backordered_quantity' => $this->backordered_quantity, // Calculated: sum of backorder records

            // Area quantities for UI components (future area repeater)
            'area_quantities' => $areaQuantities,
        ];
    }
}
