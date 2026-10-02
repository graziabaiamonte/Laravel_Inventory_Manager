<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->authorizeAction($this->action());
    }

    public function rules(): array
    {
        return $this->validateAction($this->action());
    }

    public function action(): string
    {
        $action = match ($this->method()) {
            'POST' => 'store',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'destroy',
            default => 'get',
        };

        if ($action == 'get') {
            if ($this->routeIs('*.index')) {
                $action = 'index';
            } elseif ($this->routeIs('*.show')) {
                $action = 'show';
            } else { // Custom get route, return last url segment
                $action = $this->segments()[count($this->segments()) - 1];
            }
        }

        return $action;
    }

    public function authorizeAction($action): bool
    {
        return false;
    }

    public function validateAction($action): array
    {
        return [];
    }
}
