<?php

namespace App\Http\Resources;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Enums\RecordTypeEnum;
use App\Traits\Helpers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordResource extends JsonResource
{
    use Helpers;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $filters = $request->get('filter', []);
        $first_filter = array_key_first($filters);

        $data = [
            // Actual model table fields
            'id' => $this->id,
            'record_id' => $this->id,
            'barcode' => $this->barcode,
            'cat_number' => $this->cat_number,
            'release_id' => $this->release_id,
            'type' => $this->type,
            'type_name' => RecordTypeEnum::from($this->type)->getDescription(),
            'title' => $this->title,
            'retail_price' => $this->retail_price?->formatByDecimal(),
            'retail_price_formatted' => $this->retail_price ? $this->formatCurrency($this->retail_price) : null,
            'wholesale_price' => $this->wholesale_price?->formatByDecimal(),
            'wholesale_price_formatted' => $this->wholesale_price ? $this->formatCurrency($this->wholesale_price) : null,
            'purchase_price' => $this->purchase_price?->formatByDecimal(),
            'purchase_price_formatted' => $this->purchase_price ? $this->formatCurrency($this->purchase_price) : null,
            'disk_status' => $this->disk_status,
            'disk_status_name' => DiskStatusEnum::from($this->disk_status)->getDescription(),
            'cover_status' => $this->cover_status,
            'cover_status_name' => CoverStatusEnum::from($this->cover_status)->getDescription(),
            'for_sale_on_discogs' => $this->for_sale_on_discogs,
            'discogs_id' => $this->discogs_id,
            'description' => $this->description,
            'comments' => $this->comments,
            'location_text' => $this->location_text,
            'format_id' => $this->format_id,
            'label_id' => $this->label_id,
            'artist_id' => $this->artist_id,
            'total_stocks' => $this->total_stocks,
            'total_warehouse_stocks' => (int) ($this->total_warehouse_stocks ?? 0),

            // Relationships
            'format' => $this->format,
            'label' => $this->label,
            'artist' => $this->artist,
            'stock' => StockResource::collection($this->whenLoaded('stocks')),

            // Extra fields for the UI
            'rr_uid' => $this->rr_uid,
            'format_name' => $this->format?->name,
            'label_name' => $this->label?->name,
            'artist_name' => $this->artist?->name,

            // Media files
            'media' => [...$this->getMedia('records')->map(function ($media) {
                return [
                    'id' => $media->id,
                    'uuid' => $media->uuid,
                    'name' => $media->name,
                    'file_name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'order_column' => $media->order_column,
                    // 'original_url' => $media->getUrl(),
                    'original_url' => route('media.show', $media->id),
                ];
            })],

            // Dates and timestamps
            'created_at' => $this->created_at?->toDateTimeString(),
            'last_sale' => array_key_exists('last_sale_date', $this->resource->getAttributes())
                ? $this->last_sale_date
                : $this->getLastSaleDate(),
        ];

        if ($first_filter) {
            $data['autocomplete_display'] = match ($first_filter) {
                'barcode' => "{$this->barcode} - {$this->title}",
                'cat_number' => "{$this->cat_number} - {$this->title}",
                'artist_id' => "{$this->artist->name} - {$this->title}",
                default => $this->title
            };
        }

        return $data;
    }
}
