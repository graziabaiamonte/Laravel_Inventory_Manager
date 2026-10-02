<?php

namespace App\Http\Resources;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordsImportRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $data = [
            // Actual model table fields
            'id' => $this->id,
            'record_id' => $this->record_id,
            'barcode' => $this->barcode,
            'cat_number' => $this->cat_number,
            'release_id' => $this->release_id,
            'title' => $this->title,
            'retail_price' => $this->retail_price?->formatByDecimal(),
            'wholesale_price' => $this->wholesale_price?->formatByDecimal(),
            'purchase_price' => $this->purchase_price?->formatByDecimal(),
            // Needed by the UI to warn before publishing records on Discogs with a retail price of 0
            'for_sale_on_discogs' => $this->for_sale_on_discogs,
            // Relationships as objects (what frontend expects)
            'artist' => $this->artist ? ['id' => $this->artist->id, 'name' => $this->artist->name] : null,
            'format' => $this->format ? ['id' => $this->format->id, 'name' => $this->format->name] : null,
            'label' => $this->label ? ['id' => $this->label->id, 'name' => $this->label->name] : null,
            // String fallbacks for compatibility and input handling
            'artist_name' => $this->artist?->name,
            'format_name' => $this->format?->name,
            'label_name' => $this->label?->name,
            // 'supplier_name' => $this->supplier?->name,
            'condition_disk' => DiskStatusEnum::from($this->disk_status)->getDescription(),
            'condition_cover' => CoverStatusEnum::from($this->cover_status)->getDescription(),
            'comments' => $this->comments,
            'description' => $this->description,
            // Additional helper fields
            'is_linked_to_existing_record' => ! is_null($this->record_id),
            'linked_record_title' => $this->record?->title,
            'soft_delete' => $this->soft_delete,
            'delete' => $this->delete,
            'd_delete' => $this->d_delete,
            'stocks_tmp' => $this->stocks_tmp,
        ];

        return $data;
    }
}
