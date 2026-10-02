<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ExcludeRecordInDraftScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereDoesntHave('recordsImportRecordTmp', function (Builder $query) {
            $query->whereHas('recordsImport', function (Builder $subQuery) {
                $subQuery->where('draft', 1);
            });
        });
    }
}
