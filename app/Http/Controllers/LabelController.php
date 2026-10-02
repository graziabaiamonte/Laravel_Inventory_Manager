<?php

namespace App\Http\Controllers;

use App\Http\Requests\LabelRequest;
use App\Models\Label;
use App\Traits\Helpers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class LabelController extends Controller
{
    use Helpers;

    public function index(Request $request)
    {

        if ($request->wantsJson()) {
            return $this->autocompList(Label::class);
        }

        $baseQuery = QueryBuilder::for(Label::class)
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Label/Index', [
            'labels' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function indexTrash(Request $request)
    {
        $baseQuery = QueryBuilder::for(Label::class)
            ->onlyTrashed()
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Label/Trash/Index', [
            'labels' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function create()
    {
        return Inertia::render('Label/Create');
    }

    public function store(LabelRequest $request)
    {
        $label = Label::create($request->validated());

        return redirect()
            ->route('label.edit', $label->id)
            ->with('success', 'Etichetta creata con successo!');
    }

    public function edit(Label $label)
    {
        return Inertia::render('Label/Edit', [
            'label' => $label,
        ]);
    }

    public function update(LabelRequest $request, Label $label)
    {
        $label->update($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Etichetta salvata!');
    }

    public function restore(Label $label)
    {
        // $user = User::withTrashed();
        if (! $label->trashed()) {
            return back()->withErrors('Etichetta non è nel cestino.');
        }

        $label->restore();

        return back()->with('success', 'Etichetta ripristinato con successo');
    }

    public function destroy(?Label $label, LabelRequest $request)
    {
        return $this->standardDestroy(
            Label::class,
            $label,
            $request,
            relationshipChecks: [
                'records' => 'Impossibile eliminare l\'etichetta "[NAME]" perché è ancora associata a [COUNT] disco/i.',
            ],
            messages: [
                'bulk' => 'Etichette eliminate con successo',
                'force' => 'Etichetta eliminata definitivamente',
                'soft' => 'Etichetta eliminata con successo',
            ]
        );
    }
}
