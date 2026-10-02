<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * NOTE: This reflects the state object that the Combo component works with, when handling Eloquent results.
 * It's meant to strip extra data from the frontend consinstently accros the code, rather then Model::get(['id', 'name']) everywhere.
 * It handles an optional description prop, which can be set at query level.
 * Ex: ComboResource::collection(Model::all()),
 */
class ComboResource extends JsonResource
{
    public function toArray($request)
    {
        $res = [
            'id' => $this->id,
            'name' => $this->name,
        ];

        if ($this->description) {
            $res['description'] = $this->description;
        }

        return $res;
    }
}
