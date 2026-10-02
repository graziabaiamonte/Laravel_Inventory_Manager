<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Traits\Helpers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CustomerController extends Controller
{
    use Helpers;

    public function index(Request $request)
    {
        $baseQuery = QueryBuilder::for(Customer::class)
            ->filterByAdminRoles()
            ->allowedSorts(['name', 'last_name', 'email'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name', 'last_name', 'email']);
                }),
                AllowedFilter::callback('no_recent_orders', function (Builder $query, $value) use ($request) {
                    // Calculate the date threshold based on the selected period
                    $date = null;

                    switch ($value) {
                        case '1month':
                            $date = now()->subMonth();
                            break;
                        case '3months':
                            $date = now()->subMonths(3);
                            break;
                        case '6months':
                            $date = now()->subMonths(6);
                            break;
                        case '1year':
                            $date = now()->subYear();
                            break;
                        case 'custom':
                            // Get the custom date from the filter
                            $customDate = $request->input('filter.no_recent_orders_date');
                            if ($customDate) {
                                $date = Carbon::parse($customDate);
                            }
                            break;
                    }

                    if ($date) {
                        // Filter customers that don't have any WholesaleOut orders since the specified date
                        // Note: Sales table uses remote_customer_id (for Discogs customers) and doesn't have
                        // a direct relationship with the customers table, so we only check WholesaleOut
                        $query->whereDoesntHave('wholesaleOuts', function (Builder $q) use ($date) {
                            $q->where('created_at', '>=', $date);
                        });
                    }
                }),
                AllowedFilter::callback('no_recent_orders_date', function (Builder $query, $value) {
                    // This filter is used by no_recent_orders_date, no direct filtering needed
                }),
            ]);

        return Inertia::render('Customer/Index', [
            'customers' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    /**
     * Display a listing of the trash resource.
     */
    public function indexTrash(Request $request)
    {
        $baseQuery = QueryBuilder::for(Customer::class)
            ->onlyTrashed()
            ->filterByAdminRoles()
            ->allowedSorts(['name', 'last_name', 'email'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name', 'last_name', 'email']);
                }),
            ]);

        return Inertia::render('Customer/Trash/Index', [
            'customers' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function create()
    {
        return Inertia::render('Customer/Create');
    }

    public function store(CustomerRequest $request)
    {
        $customer = Customer::create($request->validated());

        return redirect()
            ->route('customer.edit', $customer->id)
            ->with('success', 'Cliente creato con successo!');
    }

    public function edit(Customer $customer)
    {
        return Inertia::render('Customer/Edit', [
            'customer' => $customer,
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Cliente salvato!');
    }

    public function restore(Customer $customer)
    {
        // $user = User::withTrashed();
        if (! $customer->trashed()) {
            return back()->withErrors('il Cliente non è nel cestino.');
        }

        $customer->restore();

        return back()->with('success', 'Cliente ripristinato con successo');
    }

    public function destroy(?Customer $customer, CustomerRequest $request)
    {
        return $this->standardDestroy(
            Customer::class,
            $customer,
            $request,
            relationshipChecks: [
                'wholesaleOuts' => 'Impossibile eliminare il cliente "[NAME]" perché è ancora associato a [COUNT] scarichi.',
            ],
            messages: [
                'bulk' => 'Clienti eliminati con successo',
                'force' => 'Cliente eliminato definitivamente',
                'soft' => 'Cliente eliminato con successo',
            ]
        );
    }
}
