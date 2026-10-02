<?php

namespace App\Http\Controllers;

use App\Enums\LocationTypeEnum;
use App\Http\Requests\LocationRequest;
use App\Http\Resources\ComboResource;
use App\Models\Area;
use App\Models\Location;
use App\Traits\Helpers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class LocationController extends Controller
{
    use Helpers;

    public function index(Request $request)
    {

        if ($request->wantsJson()) {
            return $this->autocompList(Location::filterByAdminRoles());
        }

        $user = $request->user();

        $baseQuery = QueryBuilder::for(Location::class)
            ->when(! $user->hasAnyPermission(['all']), function ($q) use ($user) {
                // Use the locations relationship instead of permissions
                return $q->whereIn('id', $user->locations()->pluck('locations.id'));
            })
            ->allowedSorts(['name', 'type'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Location/Index', [
            'locations' => $baseQuery->paginate($this->perPage($request)),
            'areas' => Area::filterByAdminRoles()->get(),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function indexTrash(Request $request)
    {

        $user = $request->user();

        $baseQuery = QueryBuilder::for(Location::class)
            ->onlyTrashed()
            ->when(! $user->hasAnyPermission(['all']), function ($q) use ($user) {
                // Use the locations relationship instead of permissions
                return $q->whereIn('id', $user->locations()->pluck('locations.id'));
            })
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Location/Trash/Index', [
            'locations' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function create()
    {
        return Inertia::render('Location/Create', [
            'types' => LocationTypeEnum::getJsonValues(),
            'areas' => ComboResource::collection(Area::filterByAdminRoles()->get()),
        ]);
    }

    public function store(LocationRequest $request)
    {
        $location = Location::create($request->except(['areas']));

        // If default_wholesaleout_location is set, unset it for all other locations
        if ($request->get('default_wholesaleout_location')) {
            Location::where('id', '!=', $location->id)
                ->update(['default_wholesaleout_location' => false]);
        }

        if (($areas = $request->areas)) {
            $areaIds = collect($areas)->pluck('id')->values()->toArray();
            $location->areas()->sync($areaIds);

            if ($defaultAreaId = $request->get('default_area_id')) {
                if (! in_array($defaultAreaId, $areaIds)) {
                    $location->areas()->attach($defaultAreaId);
                }
            }
        } elseif ($defaultAreaId = $request->get('default_area_id')) {
            // If no areas but default area is set
            $location->areas()->attach($defaultAreaId);
        }

        return redirect()
            ->route('location.edit', $location->id)
            ->with('success', 'Location creato con successo!');
    }

    public function edit(LocationRequest $request, Location $location)
    {
        return Inertia::render('Location/Edit', [
            'location' => $location->load('areas'),
            'types' => LocationTypeEnum::getJsonValues(),
            'areas' => ComboResource::collection(Area::filterByAdminRoles()->get()),
        ]);
    }

    public function update(LocationRequest $request, Location $location)
    {
        // If default_wholesaleout_location is set, unset it for all other locations
        if ($request->get('default_wholesaleout_location')) {
            Location::where('id', '!=', $location->id)
                ->update(['default_wholesaleout_location' => false]);
        }

        $location->update($request->except(['areas']));

        // if($location->type !== LocationTypeEnum::WAREHOUSE) {
        //     $location->update(['default_wholesaleout_location' => false]);
        // }

        // $newAreas = $request->get('areas');
        // if (! empty($newAreas)) {
        //     $location->areas()->sync(
        //         collect($newAreas)->pluck('id')->values()->toArray()
        //     );
        // } else {
        //     $location->areas()->detach();
        // }

        // Get all areas including default_area_id
        $areaIds = collect($request->get('areas', []))
            ->pluck('id')
            ->push($request->get('default_area_id'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // Sync all areas
        $location->areas()->sync($areaIds);

        return redirect()
            ->back()
            ->with('success', 'Location salvato!');
    }

    public function restore(Location $location)
    {
        // $user = User::withTrashed();
        if (! $location->trashed()) {
            return back()->withErrors('Location non è nel cestino.');
        }

        $location->restore();

        return back()->with('success', 'Location ripristinato con successo');
    }

    public function destroy(?Location $location, LocationRequest $request)
    {

        if ($request->ids) {
            foreach ($request->ids as $id) {

                $id = intval($id);

                $locationMulti = Location::withTrashed()->find($id);

                if (! $locationMulti || $locationMulti->areas()->exists()) {
                    continue;
                }

                if ($locationMulti->trashed()) {
                    $locationMulti->forceDelete();
                } else {
                    $locationMulti->delete();
                }

            }

            return back()->with('success', 'Location eliminati con successo');
        }

        $hasAreas = $location->areas()
            ->exists();

        // $hasRecords = $location->records()
        // ->exists();

        if ($hasAreas) {
            return back()->withErrors('Non è possibile eliminare la location perché contiene delle aree.');
        }

        if ($location->trashed()) {
            $location->forceDelete();

            return back()->with('success', 'Location eliminato definitivamente');
        }

        $location->delete();

        return back()->with('success', 'Location eliminato con successo');
    }
}
