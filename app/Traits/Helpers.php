<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\QueryBuilder;

trait Helpers
{
    /*  Usage:
     *  $this->autocompList(Artist::class);     // Using class name
     *  $this->autocompList($artist);           // Using model instance
     *  $this->autocompList(Artist::query());   // Using query builder
     *
     *  Using custom search field and custom limit value:
     *  $this->autocompList(Record::query(), 'title', 10);
     */
    protected function autocompList($model, $searchField = 'name', $limit = 100)
    {
        $query = match (true) {
            is_string($model) => QueryBuilder::for($model),
            $model instanceof Model => QueryBuilder::for(get_class($model)),
            $model instanceof Builder => QueryBuilder::for($model),
            default => throw new \InvalidArgumentException('Invalid model type provided'),
        };

        // Get the search term from request
        $searchTerm = request()->input("filter.{$searchField}");

        if ($searchTerm) {
            $searchTerm = trim($searchTerm);

            // For very short searches (1-2 characters), prioritize exact matches and starts-with
            if (strlen($searchTerm) <= 2) {
                return $query
                    ->selectRaw("
                        id,
                        {$searchField}".($searchField != 'name' ? ' as name' : '').",
                        CASE 
                            WHEN {$searchField} = ? THEN 1
                            WHEN {$searchField} LIKE ? THEN 2
                            WHEN {$searchField} LIKE ? THEN 3
                            WHEN {$searchField} LIKE ? THEN 4
                            ELSE 5
                        END as search_priority
                    ", [
                        $searchTerm,                    // Exact match
                        $searchTerm.' %',             // Starts with term + space
                        $searchTerm.'%',               // Starts with term
                        '%'.$searchTerm.'%',           // Contains term anywhere
                    ])
                    ->whereRaw("({$searchField} = ? OR {$searchField} LIKE ? OR {$searchField} LIKE ? OR {$searchField} LIKE ?)", [
                        $searchTerm,
                        $searchTerm.' %',
                        $searchTerm.'%',
                        '%'.$searchTerm.'%',
                    ])
                    ->orderByRaw('search_priority, '.$searchField.', id')
                    ->limit($limit)
                    ->get([
                        'id',
                        $searchField != 'name' ?
                            ($searchField.' as name') :
                            $searchField,
                    ]);
            }

            // For longer searches, use full smart search with priority-based sorting
            $baseQuery = $query
                ->selectRaw("
                    id,
                    {$searchField}".($searchField != 'name' ? ' as name' : '').",
                    CASE 
                        WHEN {$searchField} = ? THEN 1
                        WHEN {$searchField} LIKE ? THEN 2
                        WHEN {$searchField} LIKE ? THEN 3
                        WHEN {$searchField} LIKE ? THEN 4
                        WHEN {$searchField} LIKE ? THEN 5
                        WHEN {$searchField} LIKE ? THEN 6
                        ELSE 7
                    END as search_priority
                ", [
                    $searchTerm,                    // Exact match
                    $searchTerm.' %',             // Starts with term + space
                    '% '.$searchTerm,             // Ends with space + term
                    '% '.$searchTerm.' %',      // Word boundary match
                    $searchTerm.'%',              // Starts with term
                    '%'.$searchTerm,               // Ends with term
                ])
                ->whereRaw("{$searchField} LIKE ?", ['%'.$searchTerm.'%'])
                ->orderByRaw('search_priority, '.$searchField.', id');

            // Apply pagination if requested
            if (request()->has('page')) {
                $page = (int) request('page', 1);
                $perPage = max(1, min((int) request('per_page', 100), 500));
                $offset = ($page - 1) * $perPage;

                // Get one extra record to check if there are more results
                $results = $baseQuery->offset($offset)->limit($perPage + 1)->get();
                $hasMore = $results->count() > $perPage;

                // Return only the requested number of items
                $items = $results->take($perPage);

                return [
                    'data' => $items,
                    'has_more' => $hasMore,
                    'current_page' => $page,
                    'per_page' => $perPage,
                ];
            }

            return $baseQuery->limit($limit)->get();
        }

        // Fallback to original behavior when no search term
        return $query
            ->allowedFilters([$searchField])
            ->defaultSort($searchField)
            ->limit($limit)
            ->get([
                'id',
                $searchField != 'name' ?
                    ($searchField.' as name') :
                    $searchField,
            ]);
    }

    /**
     * Clean up barcode by removing unwanted characters
     * Trims whitespace and removes: spaces, underscores, dashes, < and >
     */
    protected static function cleanBarcode(?string $barcode): string
    {
        if (empty($barcode)) {
            return '';
        }

        return preg_replace('/[\s_\-<>]/', '', trim($barcode));
    }

    /**
     * Format a Money object for custom display
     * Returns format: "€ 1000,99" (no thousand separator, comma as decimal)
     *
     * @param  \Cknow\Money\Money|null  $money  The Money object to format
     * @return string Formatted currency string with € prefix and comma as decimal separator
     */
    protected function formatCurrency($money): string
    {
        if ($money === null) {
            return '€ 0,00';
        }

        // Get decimal format (e.g., "1000.00")
        $decimal = $money->formatByDecimal();

        // Replace dot with comma for Italian format
        $italian = str_replace('.', ',', $decimal);

        // Add € prefix with space
        return '€ '.$italian;
    }

    /**
     * Check if the current user has access to a specific area
     * Used by requests to validate area authorization for managers
     *
     * @param  int  $areaId  The area ID to check access for
     * @return bool True if user has access or has 'all' permission, false otherwise
     */
    protected function validateAreaAccess($areaId): bool
    {
        $user = $this->user();

        if (! $user || $user->hasAnyPermission(['all'])) {
            return true;
        }

        return $user->locations()
            ->whereExists(function ($query) use ($areaId) {
                $query->from('area_location')
                    ->whereColumn('area_location.location_id', 'locations.id')
                    ->where('area_location.area_id', $areaId);
            })
            ->exists();
    }

    /**
     * Check if the current user has access to a specific location
     * Used by requests to validate location authorization for managers
     *
     * @param  int  $locationId  The location ID to check access for
     * @return bool True if user has access or has 'all' permission, false otherwise
     */
    protected function validateLocationAccess($locationId): bool
    {
        $user = $this->user();

        if (! $user || $user->hasAnyPermission(['all'])) {
            return true;
        }

        return $user->locations()->where('locations.id', $locationId)->exists();
    }

    /**
     * Handle standard soft delete with optional relationship checks
     *
     * @param  string  $modelClass  The model class name
     * @param  Model|null  $instance  Single instance to delete (null for bulk)
     * @param  \Illuminate\Http\Request  $request  Request object containing possible 'ids' for bulk delete
     * @param  array  $relationshipChecks  Array of ['relation' => 'error_message'] to check before soft delete
     * @param  array  $messages  Custom success messages ['bulk', 'force', 'soft']
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function standardDestroy(
        string $modelClass,
        ?Model $instance,
        $request,
        array $relationshipChecks = [],
        array $messages = []
    ) {
        // Handle bulk deletion
        if ($request->ids) {
            foreach ($request->ids as $id) {
                $id = intval($id);

                $model = $modelClass::withTrashed()->find($id);

                if (! $model) {
                    continue;
                }

                if ($model->trashed()) {
                    $model->forceDelete();
                } else {
                    // Check relationships before soft deleting
                    foreach ($relationshipChecks as $relation => $errorTemplate) {
                        $count = $model->{$relation}()->count();
                        if ($count > 0) {
                            $message = str_replace('[NAME]', $model->name ?? $model->full_name ?? '', $errorTemplate);
                            $message = str_replace('[COUNT]', $count, $message);

                            return back()->withErrors($message);
                        }
                    }
                    $model->delete();
                }
            }

            return back()->with('success', $messages['bulk'] ?? 'Record eliminati con successo');
        }

        // Handle single deletion - force delete from trash
        if ($instance->trashed()) {
            $instance->forceDelete();

            return back()->with('success', $messages['force'] ?? 'Record eliminato definitivamente');
        }

        // Handle single deletion - soft delete with relationship checks
        foreach ($relationshipChecks as $relation => $errorTemplate) {
            $count = $instance->{$relation}()->count();
            if ($count > 0) {
                $message = str_replace('[NAME]', $instance->name ?? $instance->full_name ?? '', $errorTemplate);
                $message = str_replace('[COUNT]', $count, $message);

                return back()->withErrors($message);
            }
        }

        $instance->delete();

        return back()->with('success', $messages['soft'] ?? 'Record eliminato con successo');
    }
}
