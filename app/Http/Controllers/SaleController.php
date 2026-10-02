<?php

namespace App\Http\Controllers;

use App\Enums\LocationTypeEnum;
use App\Enums\RecordTypeEnum;
use App\Enums\SaleTypeEnum;
use App\Http\Requests\SaleRequest;
use App\Http\Resources\ComboResource;
use App\Http\Resources\SaleRecordResource;
use App\Http\Resources\SaleResource;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleRecord;
use App\Models\Stock;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $baseQuery = QueryBuilder::for(Sale::class)
            ->filterByAdminRoles()
            ->leftJoin('locations', 'sales.location_id', '=', 'locations.id')
            ->select('sales.*')
            ->with([
                'user',
                'location',
                'saleRecords.stock',
                'saleRecords.record' => function ($q) {
                    $q->addSelect([
                        'last_sale_date' => SaleRecord::select('sales.date')
                            ->join('sales', 'sale_records.sale_id', '=', 'sales.id')
                            ->whereColumn('sale_records.record_id', 'records.id')
                            ->orderByDesc('sales.date')
                            ->limit(1),
                    ])->withSum('stocks as total_stocks', 'quantity');
                },
                'saleRecords.record.format',
                'saleRecords.record.artist',
                'saleRecords.record.label',
                'saleRecords.record.media',
                'saleRecords.record.stocks.area.locations',
                'saleRecords.record.wholesaleInRecords.wholesaleIn.supplier',
            ])
            ->defaultSort('-date')
            ->allowedSorts([
                'date',
                'amount',
                'type',
                'remote_customer_name',
                AllowedSort::field('locations.name'),
            ])
            ->allowedFilters([
                AllowedFilter::exact('locations.id'),
                AllowedFilter::exact('type'),
                // AllowedFilter::exact('remote_customer_name'),
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['remote_customer_name', 'description']);
                }),
                AllowedFilter::callback('date', function (Builder $query, $value) {
                    $query->whereBetween('date', [$value['startDate'], $value['endDate']]);
                }),
                AllowedFilter::callback('supplier_id', function (Builder $query, $value) {
                    $query->whereHas('saleRecords.record.wholesaleInRecords.wholesaleIn', function (Builder $q) use ($value) {
                        $q->where('supplier_id', $value);
                    });
                }),
            ]);

        $filters = $request->get('filter', []);
        $selectedSupplier = null;
        if (isset($filters['supplier_id']) && $filters['supplier_id']) {
            $selectedSupplier = Supplier::find(intval($filters['supplier_id']));
        }

        // Get sales summary data
        $dailyTotal = Sale::filterByAdminRoles()->whereDate('date', now())->sum('amount');
        $monthlyTotal = Sale::filterByAdminRoles()
            ->whereYear('date', now()->year)
            ->whereMonth('date', now()->month)
            ->sum('amount');

        // Calculate range total based on ALL applied filters (not just date)
        // Clone the base query to calculate the total without pagination
        $rangeTotal = (clone $baseQuery)->sum('sales.amount');

        return Inertia::render('Sale/Index', [
            'sales' => SaleResource::collection($baseQuery->paginate($this->perPage($request))),
            'locations' => ComboResource::collection(Location::filterByAdminRoles()->where('status', 1)->where('type', LocationTypeEnum::STORE)->get()),
            'applied_filters' => $request->get('filter', []),
            'types' => SaleTypeEnum::getJsonValues(),
            'selectedSupplier' => $selectedSupplier,
            'salesSummary' => [
                'day' => money($dailyTotal)->formatByDecimal(),
                'month' => money($monthlyTotal)->formatByDecimal(),
                'range' => money($rangeTotal)->formatByDecimal(),
            ],
        ]);
    }

    public function create()
    {
        /** @var \App\Models\User $user */
        $user = request()->user();

        // Get user's default location if it's a store type
        $defaultLocation = null;
        if ($user->defaultLocation && $user->defaultLocation->type === LocationTypeEnum::STORE) {
            $defaultLocation = new ComboResource($user->defaultLocation);
        }

        return Inertia::render('Sale/Create', [
            'locations' => ComboResource::collection(Location::filterByAdminRoles()->where('status', 1)->where('type', LocationTypeEnum::STORE)->get()),
            'defaultLocation' => $defaultLocation,
            'types' => SaleTypeEnum::getJsonValues(),
            'types_status' => RecordTypeEnum::getJsonValues(),
        ]);
    }

    public function store(SaleRequest $request)
    {
        $validatedData = $request->validated();
        $validatedData['amount'] = floatval($validatedData['amount']);

        $validatedData['user_id'] = $request->user()->id;

        $sale = Sale::create($validatedData);

        if ($request->records) {

            foreach ($request->records as $position => $record) {
                $saleRecordId = intval($record['id'] ?? 0);
                $recordId = intval($record['record_id']);
                $quantity = intval($record['quantity']);
                $discount = floatval($record['discount'] ?? 0);
                $vat = intval($record['vat'] ?? 0);
                $retail_price = floatval($record['price'] ?? 0);
                $stock_id = intval($record['stock_id'] ?? 0);

                $quantityDifference = $quantity;

                // get the quantity difference if updating an existing sale record
                if ($saleRecordId) {
                    $saleRecord = $sale->saleRecords()->findOrFail($saleRecordId);
                    $oldStockId = $saleRecord->stock_id ?? 0;
                    $oldQuantity = $saleRecord->quantity ?? 0;
                    $quantityDifference = $quantity - $oldQuantity;
                }

                if (! $stock_id) {
                    $location = Location::findOrFail(intval($sale->location_id));
                    $areaId = $location->defaultArea->id ?? $location->areas->first()->id ?? 0;
                    $areaId = intval($areaId);

                    $existingStock = Stock::where('record_id', $recordId)
                        ->where('area_id', $areaId)
                        ->first();

                    if ($existingStock) {
                        // Aggiorna lo stock esistente
                        $existingStock->update([
                            'quantity' => $existingStock->quantity - $quantityDifference,
                        ]);
                        $stock_id = intval($existingStock->id);
                    } else {

                        $stock = Stock::create([
                            'record_id' => $recordId,
                            'quantity' => -$quantity,
                            'area_id' => $areaId,
                        ]);
                        $stock_id = intval($stock->id);
                    }

                } else {
                    $existingStock = Stock::find($stock_id);

                    if ($existingStock) {

                        // Aggiorna lo stock esistente
                        $existingStock->update([
                            'quantity' => $existingStock->quantity - $quantityDifference,
                        ]);
                    } else {
                        // Se lo stock non esiste, crea un nuovo record di stock
                        $stock = Stock::create([
                            'record_id' => $recordId,
                            'quantity' => -$quantity,
                            'area_id' => Location::findOrFail(intval($sale->location_id))->defaultArea->id ?? 0,
                        ]);
                        $stock_id = intval($stock->id);
                    }
                }

                if ($saleRecordId) {

                    $saleRecord = $sale->saleRecords()->findOrFail($saleRecordId);

                    $saleRecord->update([
                        'position' => $position,
                        'stock_id' => $stock_id,
                        'quantity' => $quantity,
                        'discount' => $discount,
                        'price' => $retail_price,
                        'vat' => $vat,
                        'total_price' => $quantity * ($retail_price) * (1 - ($discount / 100)),
                    ]);

                } else {

                    $saleRecord = SaleRecord::create([
                        'sale_id' => $sale->id,
                        'position' => $position,
                        'record_id' => $recordId,
                        'stock_id' => $stock_id,
                        'quantity' => $quantity,
                        'discount' => $discount,
                        'price' => $retail_price,
                        'vat' => $vat,
                        'total_price' => $quantity * ($retail_price) * (1 - ($discount / 100)),
                    ]);
                }

            }

        }

        return redirect()
            ->route('sale.edit', $sale->id)
            ->with('success', 'Vendita creata con successo!');
    }

    public function edit(Sale $sale)
    {
        return Inertia::render('Sale/Edit', [
            'sale' => SaleResource::make($sale->load(['user', 'location'])),
            'locations' => ComboResource::collection(Location::filterByAdminRoles()->where('status', 1)->where('type', LocationTypeEnum::STORE)->get()),
            'types' => SaleTypeEnum::getJsonValues(),
            'sale_records' => SaleRecordResource::collection(
                SaleRecord::where('sale_id', $sale->id)
                    ->orderBy('position')
                    // id DESC tie-break so pre-existing sales (all position 0) keep their previous order
                    ->orderBy('id', 'desc')
                    ->with([
                        'stock',
                        'record' => function ($q) {
                            $q->addSelect([
                                'last_sale_date' => SaleRecord::select('sales.date')
                                    ->join('sales', 'sale_records.sale_id', '=', 'sales.id')
                                    ->whereColumn('sale_records.record_id', 'records.id')
                                    ->orderByDesc('sales.date')
                                    ->limit(1),
                            ])->withSum('stocks as total_stocks', 'quantity');
                        },
                        'record.stocks.area.locations',
                        'record.format',
                        'record.artist',
                        'record.label',
                        'record.media',
                        'record.wholesaleInRecords.wholesaleIn.supplier',
                    ])
                    ->get()
            ),
            'types_status' => RecordTypeEnum::getJsonValues(),
        ]);
    }

    public function update(SaleRequest $request, Sale $sale)
    {

        $validatedData = $request->validated();
        unset($validatedData['records']);

        $validatedData['amount'] = floatval($validatedData['amount']);

        $validatedData['user_id'] = $request->user()->id;

        $sale->update($validatedData);

        $processedSaleRecordIds = [];

        if ($request->records) {

            foreach ($request->records as $position => $record) {
                $saleRecordId = intval($record['id'] ?? 0);
                $recordId = intval($record['record_id']);
                $quantity = intval($record['quantity']);
                $discount = floatval($record['discount'] ?? 0);
                $vat = intval($record['vat'] ?? 0);
                $retail_price = floatval($record['price'] ?? 0);
                $stock_id = intval($record['stock_id'] ?? 0);

                $quantityDifference = $quantity;

                $oldStockId = 0;

                // get the quantity difference if updating an existing sale record
                if ($saleRecordId) {
                    $saleRecord = $sale->saleRecords()->findOrFail($saleRecordId);
                    $oldStockId = $saleRecord->stock_id ?? 0;
                    $oldQuantity = $saleRecord->quantity ?? 0;
                    $quantityDifference = $quantity - $oldQuantity;
                }

                if (! $stock_id) {
                    $location = Location::findOrFail(intval($sale->location_id));
                    $areaId = $location->defaultArea->id ?? $location->areas->first()->id ?? 0;
                    $areaId = intval($areaId);

                    $existingStock = Stock::where('record_id', $recordId)
                        ->where('area_id', $areaId)
                        ->first();

                    if ($existingStock) {
                        // Aggiorna lo stock esistente
                        $existingStock->update([
                            'quantity' => $existingStock->quantity - $quantityDifference,
                        ]);
                        $stock_id = intval($existingStock->id);
                    } else {

                        $stock = Stock::create([
                            'record_id' => $recordId,
                            'quantity' => -$quantity,
                            'area_id' => $areaId,
                        ]);
                        $stock_id = intval($stock->id);
                    }

                } else {
                    $existingStock = Stock::find($stock_id);

                    if ($existingStock) {

                        if ($oldStockId && $oldStockId != $stock_id) {
                            $quantityDifference = $quantity;
                        }
                        // Aggiorna lo stock esistente
                        $existingStock->update([
                            'quantity' => $existingStock->quantity - $quantityDifference,
                        ]);

                    } else {
                        // Se lo stock non esiste, crea un nuovo record di stock
                        $stock = Stock::create([
                            'record_id' => $recordId,
                            'quantity' => -$quantity,
                            'area_id' => Location::findOrFail(intval($sale->location_id))->defaultArea->id ?? 0,
                        ]);
                        $stock_id = intval($stock->id);
                    }
                }

                if ($saleRecordId) {

                    $saleRecord = $sale->saleRecords()->findOrFail($saleRecordId);

                    $saleRecord->update([
                        'position' => $position,
                        'stock_id' => $stock_id,
                        'quantity' => $quantity,
                        'price' => $retail_price,
                        'vat' => $vat,
                        'discount' => $discount,
                        'total_price' => $quantity * ($retail_price) * (1 - ($discount / 100)),
                    ]);

                    if ($oldStockId && $oldStockId != $stock_id) {
                        // Aggiorna lo stock precedente se il record è stato spostato in un altro stock
                        $oldStock = Stock::find($oldStockId);

                        if ($oldStock) {
                            $oldStock->update(['quantity' => $oldStock->quantity + $oldQuantity]);

                            if ($oldStock->quantity === 0) {
                                $oldStock->delete();
                            }

                        }
                    }

                } else {

                    $saleRecord = SaleRecord::create([
                        'sale_id' => $sale->id,
                        'position' => $position,
                        'record_id' => $recordId,
                        'stock_id' => $stock_id,
                        'quantity' => $quantity,
                        'price' => $retail_price,
                        'vat' => $vat,
                        'discount' => $discount,
                        'total_price' => $quantity * $retail_price * (1 - ($discount / 100)),
                    ]);
                }

                $processedSaleRecordIds[] = $saleRecord->id;

            }

        }

        $existingRecordIds = $sale->saleRecords()->pluck('id')->toArray();
        $idsToDelete = array_diff($existingRecordIds, $processedSaleRecordIds);

        if (! empty($idsToDelete)) {

            $recordsToDelete = SaleRecord::whereIn('id', $idsToDelete)->get();

            // Per ogni record che verrà eliminato, aggiorniamo lo stock
            foreach ($recordsToDelete as $record) {
                $stock = Stock::where('record_id', intval($record->record_id))
                    ->where('id', intval($record->stock_id))
                    ->first();

                if ($stock) {
                    // Restoring stock never removes the row: a zero or still
                    // negative result is a real quantity, and the row is the
                    // record/area identity that documents reference by id.
                    $stock->update([
                        'quantity' => $stock->quantity + $record->quantity,
                    ]);
                }
            }

            SaleRecord::destroy($idsToDelete);
        }

        return redirect()
            ->back()
            ->with('success', 'Vendita aggiornata!');
    }

    public function destroy(?Sale $sale, SaleRequest $request)
    {

        if ($request->ids) {
            foreach ($request->ids as $id) {

                $id = intval($id);

                $saleMulti = Sale::filterByAdminRoles()->find($id);

                if (! $saleMulti) {
                    continue;
                }

                $saleMulti->delete();

            }

            return back()->with('success', 'Vendite eliminate con successo');
        }

        $this->authorize('delete', $sale);
        $sale->delete();

        return back()->with('success', 'Vendita eliminata con successo');
    }

    // CardSales dashboard widget
    public function getSummary(Request $request)
    {
        if ($request->wantsJson()) {
            // Handles custom time range selection updates from the dashboard
            $startDate = $request->input('dateRange.startDate');
            $endDate = $request->input('dateRange.endDate');

            $rangeTotal = Sale::filterByAdminRoles()
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('amount');

            // We're just updating the custom 'range' data
            return response()->json([
                'range' => money($rangeTotal)->formatByDecimal(),
            ]);
        }

        // For initial dashboard data
        $dailyTotal = Sale::filterByAdminRoles()->whereDate('date', now())->sum('amount');
        $monthlyTotal = Sale::filterByAdminRoles()
            ->whereYear('date', now()->year)
            ->whereMonth('date', now()->month)
            ->sum('amount');
        $yearlyTotal = Sale::filterByAdminRoles()->whereYear('date', now()->year)->sum('amount');

        return [
            'day' => money($dailyTotal)->formatByDecimal(),
            'month' => money($monthlyTotal)->formatByDecimal(),
            'range' => 0, // money($yearlyTotal)->formatByDecimal(),
        ];
    }

    public function export(Request $request)
    {
        // Get filters and sorts from request
        $filters = $request->get('filter', []);
        $sorts = $this->parseSorts($request);

        // The jobs run without an authenticated user, so pass the location scope explicitly
        $user = $request->user();
        $allowedLocationIds = $user->hasAnyPermission(['all'])
            ? null
            : $user->locations()->pluck('locations.id')->all();

        // Dispatch the export job
        $exportJob = new \App\Jobs\InitiateSaleExportJob($filters, $sorts, 1000, $allowedLocationIds);
        $exportId = $exportJob->handle();

        return response()->json([
            'success' => true,
            'export_id' => $exportId,
            'message' => 'Esportazione avviata. Sarai notificato al completamento.',
        ]);
    }

    public function exportStatus(Request $request, string $exportId)
    {
        $progressFile = "exports/{$exportId}/progress.json";

        if (! Storage::disk('local')->exists($progressFile)) {
            return response()->json([
                'success' => false,
                'message' => 'Export not found',
            ], 404);
        }

        $progress = json_decode(Storage::disk('local')->get($progressFile), true);

        return response()->json([
            'success' => true,
            'progress' => $progress,
        ]);
    }

    public function cancelExport(Request $request, string $exportId)
    {
        $progressFile = "exports/{$exportId}/progress.json";

        if (! Storage::disk('local')->exists($progressFile)) {
            return response()->json([
                'success' => false,
                'message' => 'Export not found',
            ], 404);
        }

        // Update progress to cancelled
        $progress = json_decode(Storage::disk('local')->get($progressFile), true);
        $progress['status'] = 'cancelled';
        $progress['message'] = 'Esportazione cancellata dall\'utente';
        $progress['updated_at'] = now()->toISOString();

        Storage::disk('local')->put($progressFile, json_encode($progress));

        // Delete related jobs from the queue to stop processing immediately
        $deletedJobs = DB::table('jobs')
            ->where('payload', 'like', '%ExportSalesJob%')
            ->where('payload', 'like', "%{$exportId}%")
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Esportazione cancellata con successo',
        ]);
    }

    public function downloadExport(Request $request, string $exportId)
    {
        $filePath = "exports/{$exportId}/sales.xlsx";

        if (! Storage::disk('local')->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Export file not found or not ready yet',
            ], 404);
        }

        $fileName = 'sales-'.date('Y-m-d-H-i-s').'.xlsx';
        $fullPath = Storage::disk('local')->path($filePath);

        return response()->download($fullPath, $fileName);
    }

    protected function parseSorts(Request $request): array
    {
        $sorts = [];
        $sortParam = $request->get('sort', '');

        if ($sortParam) {
            $sortFields = explode(',', $sortParam);
            foreach ($sortFields as $sortField) {
                if (str_starts_with($sortField, '-')) {
                    $sorts[] = [
                        'field' => substr($sortField, 1),
                        'direction' => 'desc',
                    ];
                } else {
                    $sorts[] = [
                        'field' => $sortField,
                        'direction' => 'asc',
                    ];
                }
            }
        }

        return $sorts;
    }
}
