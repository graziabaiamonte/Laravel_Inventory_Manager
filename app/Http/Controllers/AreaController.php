<?php

namespace App\Http\Controllers;

use App\Http\Requests\AreaRequest;
use App\Models\Area;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AreaController extends Controller
{
    public function index(Request $request)
    {
        $baseQuery = QueryBuilder::for(Area::class)
            ->FilterByAdminRoles()
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Area/Index', [
            'areas' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function indexTrash(Request $request)
    {
        $baseQuery = QueryBuilder::for(Area::class)
            ->onlyTrashed()
            ->FilterByAdminRoles()
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Area/Trash/Index', [
            'areas' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function create()
    {
        return Inertia::render('Area/Create');
    }

    public function store(AreaRequest $request)
    {
        $area = Area::create($request->validated());

        return redirect()
            ->route('area.edit', $area->id)
            ->with('success', 'Area created successfully!');
    }

    public function edit(Area $area)
    {
        return Inertia::render('Area/Edit', [
            'area' => $area,
        ]);
    }

    public function update(AreaRequest $request, Area $area)
    {
        $area->update($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Area saved!');
    }

    public function restore(Area $area)
    {
        // $user = User::withTrashed();
        if (! $area->trashed()) {
            return back()->withErrors('Area non è nel cestino.');
        }

        $area->restore();

        return back()->with('success', 'Area ripristinata con successo');
    }

    public function destroy(?Area $area, AreaRequest $request)
    {

        if ($request->ids) {
            foreach ($request->ids as $id) {

                $id = intval($id);

                $areaMulti = Area::withTrashed()->find($id);

                if (! $areaMulti) {
                    continue;
                }

                try {

                    if ($areaMulti->trashed()) {
                        $areaMulti->forceDelete();
                    } else {
                        $areaMulti->delete();
                    }

                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() == '23000') {
                        return back()->withErrors('Non si puo eliminare definitivamente l\'area perche associata ad altri elementi');
                    }
                    throw $e;
                }

            }

            return back()->with('success', 'Areas eliminati con successo');
        }
        try {
            if ($area->trashed()) {
                $area->forceDelete();

                return back()->with('success', 'Area eliminata definitivamente');
            }

            $area->delete();

            return back()->with('success', 'Area deleted successfully');

        } catch (\Illuminate\Database\QueryException $e) {

            if ($e->getCode() == '23000') {
                return back()->withErrors('Non si puo eliminare definitivamente l\'area perche associata ad altri elementi');
            }
            throw $e;
        }
    }
}
