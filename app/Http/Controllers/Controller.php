<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    public const MAX_PER_PAGE = 500;

    /**
     * Page size requested by the index tables, capped so a crafted request can't load a whole table
     */
    protected function perPage(Request $request, int $default = 100): int
    {
        return max(1, min((int) $request->get('per_page', $default), self::MAX_PER_PAGE));
    }
}
