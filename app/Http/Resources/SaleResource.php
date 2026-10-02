<?php

namespace App\Http\Resources;

use App\Traits\Helpers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    use Helpers;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'location_id' => $this->location_id,
            'remote_customer_id' => $this->remote_customer_id,
            'remote_customer_name' => $this->remote_customer_name,
            'type' => $this->type,
            'amount' => $this->amount->formatByDecimal(),
            'amount_formatted' => $this->formatCurrency($this->amount),
            'date' => $this->date->format('Y-m-d'),
            'date_formatted' => $this->date->format('d/m/Y'),
            'description' => $this->description,
            'user' => new UserResource($this->whenLoaded('user')),
            'location' => new ComboResource($this->whenLoaded('location')),
            'sale_records' => SaleRecordResource::collection($this->whenLoaded('saleRecords')),
        ];
    }
}
