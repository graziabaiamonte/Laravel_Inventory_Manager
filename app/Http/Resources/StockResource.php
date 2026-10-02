<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockResource extends JsonResource
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
            'area_id' => $this->area_id,
            'area_name' => $this->whenLoaded('area', fn () => $this->area->name),
            'record_id' => $this->record_id,
            'quantity' => $this->quantity,
            'description' => $this->description,
            'order_column' => $this->order_column,
            'area' => $this->whenLoaded('area', function () {
                return [
                    'id' => $this->area->id,
                    'name' => $this->area->name,
                ];
            }),
            'area_location_ids' => $this->whenLoaded('area', function () {
                if ($this->area->relationLoaded('locations')) {
                    return $this->area->locations->map(function ($location) {
                        return [
                            'id' => $location->id,
                            'name' => $location->name,
                        ];
                    });
                }

                return [];
            }),
        ];
    }
}
