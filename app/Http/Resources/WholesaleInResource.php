<?php

namespace App\Http\Resources;

use App\Traits\Helpers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WholesaleInResource extends JsonResource
{
    use Helpers;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'area_id' => $this->area_id,
            'total_price' => $this->total_price->formatByDecimal(),
            'created_at' => $this->created_at->format('d/m/Y H:i'),
            'total_price_formatted' => $this->formatCurrency($this->total_price),
            'doc_num' => $this->doc_num,
            'description' => $this->description,
            'status' => $this->status,
            'file' => $this->file,
            'supplier' => new ComboResource($this->whenLoaded('supplier')),
            'area' => new ComboResource($this->whenLoaded('area')),
            'records' => WholesaleInRecordResource::collection($this->whenLoaded('records')),
        ];
    }
}
