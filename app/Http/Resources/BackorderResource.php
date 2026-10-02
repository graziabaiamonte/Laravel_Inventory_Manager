<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackorderResource extends JsonResource
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
            'customer_name' => $this->wholesaleOut->customer->full_name ?? null,
            'status' => $this->status,
            'whole_sale_out_id' => $this->wholesale_out_id,
            'created_at' => $this->created_at->format('d/m/Y H:i'),
        ];
    }
}
