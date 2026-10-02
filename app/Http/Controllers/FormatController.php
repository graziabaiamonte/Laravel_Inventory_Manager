<?php

namespace App\Http\Controllers;

use App\Http\Requests\FormatRequest;
use App\Models\Format;
use App\Traits\Helpers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class FormatController extends Controller
{
    use Helpers;

    public function index(Request $request)
    {

        if ($request->wantsJson()) {
            return $this->autocompList(Format::class);
        }

        $baseQuery = QueryBuilder::for(Format::class)
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Format/Index', [
            'formats' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function indexTrash(Request $request)
    {

        $baseQuery = QueryBuilder::for(Format::class)
            ->onlyTrashed()
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Format/Trash/Index', [
            'formats' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function create()
    {
        return Inertia::render('Format/Create');
    }

    public function store(FormatRequest $request)
    {
        $format = Format::create($request->validated());

        return redirect()
            ->route('format.edit', $format->id)
            ->with('success', 'Format creato con successo!');
    }

    public function edit(Format $format)
    {
        return Inertia::render('Format/Edit', [
            'format' => $format,
        ]);
    }

    public function update(FormatRequest $request, Format $format)
    {
        $format->update($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Format salvato!');
    }

    public function restore(Format $format)
    {
        if (! $format->trashed()) {
            return back()->withErrors('Il Formato non è nel cestino.');
        }

        $format->restore();

        return back()->with('success', 'Formato ripristinato con successo');
    }

    public function destroy(?Format $format, FormatRequest $request)
    {
        return $this->standardDestroy(
            Format::class,
            $format,
            $request,
            relationshipChecks: [
                'records' => 'Impossibile eliminare il formato "[NAME]" perché è ancora associato a [COUNT] disco/i.',
            ],
            messages: [
                'bulk' => 'Record eliminati con successo',
                'force' => 'Formato eliminato definitivamente',
                'soft' => 'Format eliminato con successo',
            ]
        );
    }
}
