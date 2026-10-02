<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Traits\Helpers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class SupplierController extends Controller
{
    use Helpers;

    public function index(Request $request)
    {

        if ($request->wantsJson()) {
            return $this->autocompList(Supplier::class);
        }

        $baseQuery = QueryBuilder::for(Supplier::class)
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name', 'email']);
                }),
            ]);

        return Inertia::render('Supplier/Index', [
            'suppliers' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function indexTrash(Request $request)
    {

        $baseQuery = QueryBuilder::for(Supplier::class)
            ->onlyTrashed()
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name', 'email']);
                }),
            ]);

        return Inertia::render('Supplier/Trash/Index', [
            'suppliers' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function create()
    {
        return Inertia::render('Supplier/Create');
    }

    public function store(SupplierRequest $request)
    {
        $supplier = Supplier::create($request->validated());

        return redirect()
            ->route('supplier.edit', $supplier->id)
            ->with('success', 'Fornitore creato con successo!');
    }

    public function edit(Supplier $supplier)
    {
        return Inertia::render('Supplier/Edit', [
            'supplier' => $supplier,
        ]);
    }

    public function update(SupplierRequest $request, Supplier $supplier)
    {
        $supplier->update($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Fornitore salvato!');
    }

    public function restore(Supplier $supplier)
    {
        // $user = User::withTrashed();
        if (! $supplier->trashed()) {
            return back()->withErrors('Fornitore non è nel cestino.');
        }

        $supplier->restore();

        return back()->with('success', 'Fornitore ripristinato con successo');
    }

    public function destroy(?Supplier $supplier, SupplierRequest $request)
    {
        return $this->standardDestroy(
            Supplier::class,
            $supplier,
            $request,
            relationshipChecks: [
                'wholesaleIns' => 'Impossibile eliminare il fornitore "[NAME]" perché è ancora associato a [COUNT] carichi.',
            ],
            messages: [
                'bulk' => 'Fornitori eliminati con successo',
                'force' => 'Fornitore eliminato definitivamente',
                'soft' => 'Fornitore eliminato con successo',
            ]
        );
    }
}
