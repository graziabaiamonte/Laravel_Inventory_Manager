<?php

namespace App\Http\Requests;

use App\Models\Label;
use Illuminate\Validation\Rule;

class LabelRequest extends BaseRequest
{
    public function authorizeAction($action): bool
    {
        switch ($action) {
            case 'index':
            case 'store':
            case 'update':
            case 'destroy':
                return true;
        }

        return false;
    }

    public function validateAction($action): array
    {
        switch ($action) {
            case 'store':
                return [
                    'name' => ['required', 'unique:labels'],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            case 'update':
                return [
                    'name' => ['required', Rule::unique(Label::class)->ignore($this->id)],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            default:
                return [];
        }
    }
}
