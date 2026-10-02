<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordsImportResource extends JsonResource
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
            'draft' => $this->draft,
            'draft_label' => ($this->draft) ? 'Bozza' : 'Pubblicato',
            'is_editable' => $this->isEditable(),
            'records_count' => $this->records_count,
            'created_at' => $this->created_at->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at->format('d/m/Y H:i'),
            // 'records' => RecordsImportRecordResource::collection($this->records()->withoutGlobalScopes()->get()),
        ];
    }
}
