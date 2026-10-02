<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WholesaleInRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
        key: 'cat_number',
        key: 'barcode',
        key: 'artist',
        key: 'title',
        key: 'format.name',
        key: 'label.name',
        key: 'quantity',
        key: 'purchase_price',
        key: 'discount',
        key: 'total_price',
        key: 'wholesale_price',
        key: 'unit_price',
        key: 'vat',
        */

        /* TS type
        export interface WholesaleInRecord {
            id: number;
            wholesale_in_id: number;
            record_id: number;
            quantity: number;
            unit_price: number;
            discount: number;
            total_price: number;
            vat: string;
            created_at?: string;
            updated_at?: string;
            parentRecord?: Record;
        }
        */

        return [
            // Actual model table fields
            'id' => $this->id,
            'wholesale_in_id' => $this->wholesale_in_id,
            'record_id' => $this->record_id,
            'quantity' => $this->quantity,
            'unit_price' => money($this->unit_price)->formatByDecimal(),
            'discount' => $this->discount,
            'total_price' => money($this->total_price)->formatByDecimal(),
            'vat' => $this->vat,

            // Relationships
            'parent_record' => $this->whenLoaded('parentRecord', fn () => new RecordResource($this->parentRecord)),

            'area_quantities' => $this->wholesaleInRecordsArea,

            // Extra fields for the UI
            'cat_number' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->cat_number),
            'barcode' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->barcode),
            'artist' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->artist?->name),
            'title' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->title),
            'format' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->format?->name),
            'label' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->label?->name),

            // Record level prices, exposed here because they are editable from the WholesaleIn UI
            'wholesale_price' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->wholesale_price ? money($this->parentRecord->wholesale_price)->formatByDecimal() : null),
            'retail_price' => $this->whenLoaded('parentRecord', fn () => $this->parentRecord->retail_price ? money($this->parentRecord->retail_price)->formatByDecimal() : null), // For "Prezzo dettaglio" column
        ];
    }
}
