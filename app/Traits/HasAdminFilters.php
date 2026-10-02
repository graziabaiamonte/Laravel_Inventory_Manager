<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasAdminFilters
{
    abstract public function scopeFilterByAdminRoles(Builder $query): Builder;
}
