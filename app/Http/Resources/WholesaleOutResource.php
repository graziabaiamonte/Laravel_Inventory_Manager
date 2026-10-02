<?php

namespace App\Http\Resources;

use App\Traits\Helpers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WholesaleOutResource extends JsonResource
{
    use Helpers;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'area_id' => $this->area_id,
            'doc_num' => $this->doc_num,
            'description' => $this->description,
            'status' => $this->status,
            'total_price' => $this->total_price->formatByDecimal(),
            'total_price_formatted' => $this->formatCurrency($this->total_price),
            'file' => $this->file, // Path or URL to the PDF
            'created_at' => $this->created_at->format('d/m/Y H:i'),
            // 'customer' => $this->whenLoaded('customer', function () {
            //     $customer = $this->customer;
            //     $customer->name = $customer->full_name;

            //     return new ComboResource($customer);
            // }),
            'customer' => $this->whenLoaded('customer', function () {
                return [
                    'id' => $this->customer->id,
                    'name' => $this->customer->full_name,
                ];
            }),
            'area' => new ComboResource($this->whenLoaded('area')),
            'records' => WholesaleOutRecordResource::collection($this->whenLoaded('records')),
            'backorders' => BackorderResource::collection($this->whenLoaded('backorders')),
            'label_discounts' => $this->whenLoaded('labelDiscounts', function () {
                return $this->labelDiscounts->map(function ($labelDiscount) {
                    return [
                        'label_id' => $labelDiscount->label_id,
                        'label_name' => $labelDiscount->label->name,
                        'discount' => $labelDiscount->discount,
                    ];
                });
            }),
        ];
    }
}
