<?php

namespace App\Http\Requests;

use App\Models\Format;
use Illuminate\Validation\Rule;

class FormatRequest extends BaseRequest
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
                    'name' => ['required', 'unique:formats'],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            case 'update':
                return [
                    'name' => ['required', Rule::unique(Format::class)->ignore($this->id)],
                    'status' => ['required', 'integer', 'in:0,1'],
                ];

            default:
                return [];
        }
    }
}
