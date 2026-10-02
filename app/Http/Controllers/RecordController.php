<?php

namespace App\Http\Controllers;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Enums\ForSaleOnDiscogsStatusEnum;
use App\Enums\LocationTypeEnum;
use App\Enums\RecordTypeEnum;
use App\Facades\Flash;
use App\Http\Requests\RecordRequest;
use App\Http\Resources\ComboResource;
use App\Http\Resources\FormatResource;
// use App\Http\Resources\WholesaleInRecordResource;
use App\Http\Resources\RecordResource;
use App\Http\Resources\StockResource;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Location;
use App\Models\Record;
use App\Models\SaleRecord;
use App\Models\Stock;
use App\Models\Supplier;
use App\Services\BarcodeLabelService;
use App\Services\External\DiscogsClient;
use App\Services\External\DiscogsListingService;
use App\Traits\Controllers\HasUploadedFiles;
use App\Traits\Helpers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class RecordController extends Controller
{
    use HasUploadedFiles, Helpers;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            $receivedFilters = $request->get('filter', []);
            $orderByTotalStock = $request->get('orderByTotalStock');
            $allowedFilters = [];

            $query = QueryBuilder::for(Record::class)
                ->with(['artist', 'label', 'format', 'media', 'stocks.area.locations'])
                ->select('records.*')
                ->addSelect([
                    'last_sale_date' => SaleRecord::select('sales.date')
                        ->join('sales', 'sale_records.sale_id', '=', 'sales.id')
                        ->whereColumn('sale_records.record_id', 'records.id')
                        ->orderByDesc('sales.date')
                        ->limit(1),
                ]);

            foreach ($receivedFilters as $key => $value) {
                switch ($key) {
                    case 'artist':
                        $query->join('artists', 'records.artist_id', '=', 'artists.id')
                            ->addSelect('records.*')
                            ->addSelect(DB::raw("CONCAT(artists.name, ' - ', records.title) as autocomplete_display"));
                        $allowedFilters[] = AllowedFilter::callback('artist', function ($query, $value) {
                            $value = \App\Support\SearchFilter::term($value);
                            $query->whereHas('artist', function ($query) use ($value) {
                                $query->where('name', 'LIKE', '%'.$value.'%');
                            });
                        });
                        break;

                    case 'barcode':
                        $query->addSelect('records.*')
                            ->addSelect(DB::raw("CONCAT(barcode, ' - ', title) as autocomplete_display"));
                        $allowedFilters[] = AllowedFilter::callback('barcode', function ($query, $value) {
                            $value = \App\Support\SearchFilter::term($value);
                            $query->where(function ($q) use ($value) {
                                $q->where('barcode', 'LIKE', '%'.$value.'%')
                                    ->orWhere('rr_uid', 'LIKE', '%'.$value.'%');
                            });
                        });
                        break;

                    case 'cat_number':
                        $query->addSelect('records.*')
                            ->addSelect(DB::raw("CONCAT(cat_number, ' - ', title) as autocomplete_display"));
                        $allowedFilters[] = AllowedFilter::callback('cat_number', function ($query, $value) {
                            $value = \App\Support\SearchFilter::term($value);
                            $query->where('cat_number', 'LIKE', '%'.$value.'%');
                        });
                        break;

                    case 'title':
                        $allowedFilters[] = AllowedFilter::callback('title', function ($query, $value) {
                            $value = \App\Support\SearchFilter::term($value);
                            $query->where('title', 'LIKE', '%'.$value.'%');
                        });
                        break;

                    case 'format':
                        $allowedFilters[] = AllowedFilter::callback('format', function ($query, $value) {
                            $value = \App\Support\SearchFilter::term($value);
                            $query->whereHas('format', function ($query) use ($value) {
                                $query->where('name', 'LIKE', '%'.$value.'%');
                            });
                        });
                        break;

                    case 'type':
                        $allowedFilters[] = AllowedFilter::exact('type');
                        break;
                }
            }

            $query->allowedFilters($allowedFilters)
                ->withSum('stocks as total_stocks', 'quantity')
                ->withSum([
                    'stocks as total_warehouse_stocks' => function ($q) {
                        $q->whereIn('area_id', DB::table('area_location')
                            ->select('area_id')
                            ->join('locations', 'area_location.location_id', '=', 'locations.id')
                            ->join('areas', 'area_location.area_id', '=', 'areas.id')
                            ->where('locations.type', LocationTypeEnum::WAREHOUSE)
                            ->where('areas.status', 1)
                        );
                    },
                ], 'quantity');

            if ($orderByTotalStock) {
                $query->orderBy('total_stocks', 'desc');
            }

            $recordBaseQuery = RecordResource::collection(
                $query->limit(50)
                    ->get()
            );

            return $recordBaseQuery;
        }

        $filters = $request->get('filter', []);

        $baseQuery = QueryBuilder::for(Record::class)
            ->filterByAdminRoles()
            ->select('records.*')
            ->leftJoin('artists', 'records.artist_id', '=', 'artists.id')
            ->leftJoin('formats', 'records.format_id', '=', 'formats.id')
            ->leftJoin('labels', 'records.label_id', '=', 'labels.id')
            ->with(['format', 'label', 'artist', 'media'])
            ->addSelect([
                'last_sale_date' => SaleRecord::select('sales.date')
                    ->join('sales', 'sale_records.sale_id', '=', 'sales.id')
                    ->whereColumn('sale_records.record_id', 'records.id')
                    ->orderByDesc('sales.date')
                    ->limit(1),
            ])
            ->withSum('stocks as total_stocks', 'quantity')
            ->withSum([
                'stocks as total_warehouse_stocks' => function ($q) {
                    $q->whereIn('area_id', DB::table('area_location')
                        ->select('area_id')
                        ->join('locations', 'area_location.location_id', '=', 'locations.id')
                        ->join('areas', 'area_location.area_id', '=', 'areas.id')
                        ->where('locations.type', LocationTypeEnum::WAREHOUSE)
                        ->where('areas.status', 1)
                    );
                },
            ], 'quantity')
            ->allowedSorts(['rr_uid', 'title', 'barcode', 'disk_status', 'cover_status',
                AllowedSort::field('artist_name', 'artists.name'),
                AllowedSort::field('format_name', 'formats.name'),
                AllowedSort::field('label_name', 'labels.name'),
                'cat_number', 'total_stocks',
            ])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, [
                        'title',
                        'barcode',
                        'cat_number',
                        'artists.name',
                        'formats.name',
                        'labels.name',
                    ]);
                }),
                AllowedFilter::callback('title', function (Builder $query, $value) {
                    $value = \App\Support\SearchFilter::term($value);
                    $query->where('records.title', 'LIKE', '%'.$value.'%');
                }),
                AllowedFilter::callback('wholesale', function (Builder $query, $value) {
                    if ($value === 'yes') {
                        $query->where(function ($query) {
                            $query->where('wholesale_price', '>', 0);
                        });
                    } elseif ($value === 'no') {
                        $query->where(function ($query) {
                            $query->where('wholesale_price', '=', 0);
                        });
                    }
                }),
                AllowedFilter::callback('supplier_id', function (Builder $query, $value) {
                    $query->whereHas('wholesaleInRecords.wholesaleIn', function (Builder $q) use ($value) {
                        $q->where('supplier_id', $value);
                    });
                }),
                AllowedFilter::callback('price_min', function (Builder $query, $value) use ($filters) {
                    $priceType = $filters['price_type'] ?? 'purchase_price';
                    if (in_array($priceType, ['purchase_price', 'wholesale_price', 'retail_price']) && $value !== '') {
                        // Multiply by 100 to match the stored integer format (cents)
                        $query->where($priceType, '>=', floatval($value) * 100);
                    }
                }),
                AllowedFilter::callback('price_max', function (Builder $query, $value) use ($filters) {
                    $priceType = $filters['price_type'] ?? 'purchase_price';
                    if (in_array($priceType, ['purchase_price', 'wholesale_price', 'retail_price']) && $value !== '') {
                        // Multiply by 100 to match the stored integer format (cents)
                        $query->where($priceType, '<=', floatval($value) * 100);
                    }
                }),
                AllowedFilter::callback('price_type', function (Builder $query, $value) {
                    // This filter is used by price_min and price_max, no direct filtering needed
                }),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('disk_status'),
                AllowedFilter::exact('cover_status'),
                AllowedFilter::exact('for_sale_on_discogs'),
                AllowedFilter::exact('artist_id'),
                AllowedFilter::exact('format_id'),
                AllowedFilter::exact('label_id'),
                AllowedFilter::exact('location_id'),
            ]);

        if (! $request->has('sort')) {
            $baseQuery->orderBy('records.created_at', 'desc');
        }

        $selectedArtist = null;
        if (isset($filters['artist_id']) && $filters['artist_id']) {
            $selectedArtist = Artist::find(intval($filters['artist_id']));
        }

        $selectedFormat = null;
        if (isset($filters['format_id']) && $filters['format_id']) {
            $selectedFormat = Format::find(intval($filters['format_id']));
        }

        $selectedLabel = null;
        if (isset($filters['label_id']) && $filters['label_id']) {
            $selectedLabel = Label::find(intval($filters['label_id']));
        }

        $selectedSupplier = null;
        if (isset($filters['supplier_id']) && $filters['supplier_id']) {
            $selectedSupplier = Supplier::find(intval($filters['supplier_id']));
        }

        return Inertia::render('Record/Index', [
            'records' => RecordResource::collection($baseQuery->paginate($this->perPage($request))),
            'applied_filters' => $request->get('filter', []),
            'types' => RecordTypeEnum::getJsonValues(),
            'diskstatus' => DiskStatusEnum::getJsonValues(),
            'coverstatus' => CoverStatusEnum::getJsonValues(),
            'forsalediscogsstatus' => ForSaleOnDiscogsStatusEnum::getJsonValues(),
            'selectedArtist' => $selectedArtist,
            'selectedFormat' => $selectedFormat,
            'selectedLabel' => $selectedLabel,
            'selectedSupplier' => $selectedSupplier,
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function indexTrash(Request $request)
    {
        $baseQuery = QueryBuilder::for(Record::class)
            ->onlyTrashed()
            ->filterByAdminRoles()
            ->select('records.*')
            ->leftJoin('artists', 'records.artist_id', '=', 'artists.id')
            ->leftJoin('formats', 'records.format_id', '=', 'formats.id')
            ->leftJoin('labels', 'records.label_id', '=', 'labels.id')
            ->with(['format', 'label', 'artist'])
            ->allowedSorts(['id', 'title', 'barcode',
                AllowedSort::field('artist_name', 'artists.name'),
                AllowedSort::field('format_name', 'formats.name'),
                AllowedSort::field('label_name', 'labels.name'),
            ])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, [
                        'title',
                        'barcode',
                        'artists.name',
                        'formats.name',
                        'labels.name',
                    ]);
                }),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('disk_status'),
                AllowedFilter::exact('cover_status'),
                AllowedFilter::exact('for_sale_on_discogs'),
                AllowedFilter::exact('supplier_id'),
                AllowedFilter::exact('artist_id'),
                AllowedFilter::exact('format_id'),
                AllowedFilter::exact('label_id'),
                AllowedFilter::exact('location_id'),
            ]);

        return Inertia::render('Record/Trash/Index', [
            'records' => RecordResource::collection($baseQuery->paginate($this->perPage($request))),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {

        return Inertia::render('Record/Create', [
            'types' => RecordTypeEnum::getJsonValues(),
            'formats' => FormatResource::collection(Format::where('status', 1)->get()),
            'diskstatus' => DiskStatusEnum::getJsonValues(),
            'coverstatus' => CoverStatusEnum::getJsonValues(),
            'forsalediscogsstatus' => ForSaleOnDiscogsStatusEnum::getJsonValues(),
            'areas' => ComboResource::collection(Area::filterByAdminRoles()->defaulLocationsAreas()
                ->with('locations')
                ->get()),
        ]);
    }

    /**
     * Location a newly created resource in storage.
     */
    public function store(RecordRequest $request)
    {

        $validatedData = $request->validated();
        unset($validatedData['stock']);
        // discogs_id is never set from user input; it is written only from Discogs' confirmation
        // when a listing is created (see update() which strips it as well).
        unset($validatedData['discogs_id']);

        // Convert empty price fields to 0 to avoid null constraint violations
        foreach (['retail_price', 'wholesale_price', 'purchase_price'] as $priceField) {
            if (empty($validatedData[$priceField])) {
                $validatedData[$priceField] = 0;
            }
        }

        // Create artist if needed
        if ($validatedData['artist_id'] === 0 && ! empty($validatedData['artist_name'])) {
            $artist = Artist::create([
                'name' => $validatedData['artist_name'],
                'status' => 1,
            ]);
            $validatedData['artist_id'] = $artist->id;
        }
        unset($validatedData['artist_name']);

        // Create label if needed
        if ($validatedData['label_id'] === 0 && ! empty($validatedData['label_name'])) {
            $label = Label::create([
                'name' => $validatedData['label_name'],
                'status' => 1,
            ]);
            $validatedData['label_id'] = $label->id;
        }
        unset($validatedData['label_name']);

        // Save for_sale_on_discogs value and temporarily remove it
        $forSaleOnDiscogs = $validatedData['for_sale_on_discogs'] ?? false;
        unset($validatedData['for_sale_on_discogs']);

        $record = Record::create($validatedData);

        // $record = Record::create($request->all());

        $this->saveUploadedFile($request, $record, 'records');

        $stocks = $request->stock ?? [];

        if (! empty($stocks)) {
            $this->createOrUpdateStock($stocks, $record);
        }

        // Now update with for_sale_on_discogs after stocks exist
        if ($forSaleOnDiscogs) {
            $record->refresh(); // Get fresh data including stock totals
            $record->defer_discogs_listing = false;
            $record->for_sale_on_discogs = true;
            $record->save(); // This will trigger updated event with isDirty('for_sale_on_discogs') = true
        }

        $errorMessage = session()->get('error');
        if ($errorMessage) {
            return redirect()
                ->route('record.edit', $record->id)
                ->withErrors(['discogs_error' => $errorMessage])
                ->with('success', 'Record creato, sono presenti errori su Discogs.');
        }

        $record->refresh();
        $this->checkAndWarnDuplicates($record);

        Flash::success('Record creato con successo!');

        return redirect()
            ->route('record.edit', $record->id);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Record $record)
    {
        $listingData = null;
        if ($record->discogs_id) {
            $result = app(DiscogsClient::class)->getListing($record);
            if ($result['success']) {
                $listingData = $result['data'];
            }
        }

        // Get user-editable area IDs for frontend to determine read-only stocks
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $editableAreaIds = $user->hasAnyPermission(['all'])
            ? null // Admin can edit all
            : Area::filterByAdminRoles()->pluck('id')->toArray();

        return Inertia::render('Record/Edit', [
            'record' => new RecordResource($record),
            'types' => RecordTypeEnum::getJsonValues(),
            'formats' => FormatResource::collection(Format::where('status', 1)->get()),
            'diskstatus' => DiskStatusEnum::getJsonValues(),
            'coverstatus' => CoverStatusEnum::getJsonValues(),
            'forsalediscogsstatus' => ForSaleOnDiscogsStatusEnum::getJsonValues(),
            'areas' => ComboResource::collection(
                $this->getAreasWithStockAreas($record)
            ),
            'editableAreaIds' => $editableAreaIds,
            'stocks' => StockResource::collection(
                Stock::where('record_id', $record->id)
                    ->with(['area'])
                    ->ordered() // use Spatie Eloquent Sortable order
                    ->get()
            ),
            'listingData' => $listingData,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(RecordRequest $request, Record $record)
    {

        $validatedData = $request->validated();
        unset($validatedData['discogs_id']);
        unset($validatedData['stock']);

        // Convert empty price fields to 0 to avoid null constraint violations
        foreach (['retail_price', 'wholesale_price', 'purchase_price'] as $priceField) {
            if (empty($validatedData[$priceField])) {
                $validatedData[$priceField] = 0;
            }
        }

        // Create artist if needed
        if ($validatedData['artist_id'] === 0 && ! empty($validatedData['artist_name'])) {
            $artist = Artist::create([
                'name' => $validatedData['artist_name'],
                'status' => 1,
            ]);
            $validatedData['artist_id'] = $artist->id;
        }
        unset($validatedData['artist_name']);

        // Create label if needed
        if ($validatedData['label_id'] === 0 && ! empty($validatedData['label_name'])) {
            $label = Label::create([
                'name' => $validatedData['label_name'],
                'status' => 1,
            ]);
            $validatedData['label_id'] = $label->id;
        }
        unset($validatedData['label_name']);

        $stocks = $request->stock ?? [];
        $forSaleOnDiscogs = $validatedData['for_sale_on_discogs'] ?? null;

        // Only defer if we're actually updating stocks
        $hasStocksToUpdate = ! empty($stocks);

        // If we have stocks, temporarily remove for_sale_on_discogs
        if ($hasStocksToUpdate) {
            unset($validatedData['for_sale_on_discogs']);
        }

        // $record->update($request->except('discogs_id'));

        $record->defer_discogs_listing = $hasStocksToUpdate;
        $record->update($validatedData);

        $this->saveUploadedFile($request, $record, 'records');

        if ($hasStocksToUpdate) {
            $stocksChanged = $this->createOrUpdateStock($stocks, $record);

            // Now update for_sale_on_discogs after stocks are saved
            if ($forSaleOnDiscogs !== null) {
                $record->refresh();
                $record->defer_discogs_listing = false;
                $record->for_sale_on_discogs = $forSaleOnDiscogs;
                $record->save(); // This will trigger updated event with isDirty('for_sale_on_discogs') = true

                // If stocks changed and record is already listed, explicitly update Discogs location
                if ($stocksChanged && $forSaleOnDiscogs && $record->discogs_id) {
                    app(DiscogsListingService::class)->updateRecordListing($record);
                }
            }
        }

        $errorMessage = session()->get('error');
        if ($errorMessage) {
            return redirect()
                ->back()
                ->withErrors(['discogs_error' => $errorMessage])
                ->with('success', 'Record aggiornato, sono presenti errori su Discogs.');
        }

        $record->refresh();
        $this->checkAndWarnDuplicates($record);

        Flash::success('Record aggiornato con successo!');

        return redirect()
            ->back();
    }

    public function restore(Record $record)
    {
        if (! $record->trashed()) {
            return back()->withErrors('Il Record non è nel cestino.');
        }

        $record->restore();

        return back()->with('success', 'Record ripristinato con successo');
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * Permanently delete a trashed record, reporting a refusal rather than a 500.
     *
     * Everything hanging off a record cascades away with it (its stocks, and
     * through those its sale lines, plus its wholesale-in lines), which is why
     * the caller warns before getting here. The one exception is the outbound
     * documents ("scarichi"):
     * wholesale_out_records.record_id, which is ON DELETE RESTRICT: the database
     * rejects the delete outright, which was surfacing as an unhandled 1451.
     *
     * Returns false when the database refused, true when the record is gone.
     */
    private function forceDeleteRecord(Record $record): bool
    {
        try {
            $record->forceDelete();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1451) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    public function destroy(?Record $record, RecordRequest $request)
    {

        if ($request->ids) {
            $blocked = [];

            foreach ($request->ids as $id) {

                $id = intval($id);

                $recordMulti = Record::withTrashed()->find($id);

                if (! $recordMulti) {
                    continue;
                }

                if ($recordMulti->trashed()) {
                    if (! $this->forceDeleteRecord($recordMulti)) {
                        $blocked[] = $recordMulti->cat_number ?: $recordMulti->barcode ?: "#{$recordMulti->id}";
                    }
                } else {
                    $recordMulti->for_sale_on_discogs = false;
                    $recordMulti->save();
                    $recordMulti->delete();
                }

            }

            if (! empty($blocked)) {
                return back()->withErrors(
                    'Non è stato possibile eliminare definitivamente '.implode(', ', $blocked).
                    ': sono presenti in uno o più scarichi e non possono essere rimossi.'
                );
            }

            return back()->with('success', 'Record eliminati con successo');
        }

        if ($record->trashed()) {
            if (! $this->forceDeleteRecord($record)) {
                return back()->withErrors(
                    'Non è possibile eliminare definitivamente questo record: '.
                    'è presente in uno o più scarichi e non può essere rimosso.'
                );
            }

            return back()->with('success', 'Record eliminato definitivamente');
        }

        $record->for_sale_on_discogs = false;
        $record->save();
        $record->delete();

        return back()->with('success', 'Record eliminato con successo');
    }

    public function export(Request $request)
    {
        // Get filters and sorts from request
        $filters = $request->get('filter', []);
        $sorts = $this->parseSorts($request);

        // Check if this is a wholesale export
        $isWholesaleExport = $request->get('wholesale', false) === 'true';

        $isUppercaseExport = $request->get('uppercase', false) === 'true';

        // Dispatch the export job
        $exportJob = new \App\Jobs\InitiateRecordExportJob($filters, $sorts, $isWholesaleExport, $isUppercaseExport);
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
            ->where('payload', 'like', '%ExportRecordsJob%')
            ->where('payload', 'like', "%{$exportId}%")
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Esportazione cancellata con successo',
        ]);
    }

    public function downloadExport(Request $request, string $exportId)
    {
        $filePath = "exports/{$exportId}/records.xlsx";

        if (! Storage::disk('local')->exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Export file not found or not ready yet',
            ], 404);
        }

        $fileName = 'records-'.date('Y-m-d-H-i-s').'.xlsx';
        $fullPath = Storage::disk('local')->path($filePath);

        // Create the download response
        $response = response()->download($fullPath, $fileName);

        // Optional: Clean up the export directory after download
        // Uncomment the next 3 lines if you want immediate cleanup after download
        // $response->deleteFileAfterSend(false);
        // register_shutdown_function(function() use ($exportId) {
        //     Storage::disk('local')->deleteDirectory("exports/{$exportId}");
        // });

        return $response;
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

    /**
     * Check for duplicate barcode/cat_number and flash a warning with links to duplicates.
     */
    private function checkAndWarnDuplicates(Record $record): void
    {
        $warnings = [];

        if (! empty($record->barcode)) {
            $duplicates = Record::where('barcode', $record->barcode)
                ->where('id', '!=', $record->id)
                ->get();
            if ($duplicates->isNotEmpty()) {
                $url = route('record.index', ['filter' => ['search' => $record->barcode]]);
                $warnings[] = "Barcode <strong>{$record->barcode}</strong> già presente su ".$duplicates->count()." record. <a href=\"{$url}\" target=\"_blank\">Vedi duplicati</a>";
            }
        }

        if (! empty($record->cat_number)) {
            $duplicates = Record::where('cat_number', $record->cat_number)
                ->where('id', '!=', $record->id)
                ->get();
            if ($duplicates->isNotEmpty()) {
                $url = route('record.index', ['filter' => ['search' => $record->cat_number]]);
                $warnings[] = "Cat# <strong>{$record->cat_number}</strong> già presente su ".$duplicates->count()." record. <a href=\"{$url}\" target=\"_blank\">Vedi duplicati</a>";
            }
        }

        if (! empty($warnings)) {
            Flash::warning(implode('<br>', $warnings));
        }
    }

    private function createOrUpdateStock($stocks, Record $record)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Get user's allowed location IDs
        $userLocationIds = $user->hasAnyPermission(['all'])
            ? null // Admin can modify all
            : $user->locations()->pluck('locations.id')->toArray();

        // Get existing stocks to detect changes and deletions
        $existingStocks = Stock::where('record_id', $record->id)->get()->keyBy('area_id');
        $submittedAreaIds = collect($stocks)->pluck('area_id')->unique();

        // Track if any stock actually changed
        $stocksChanged = false;

        // Check for deletions (stocks that existed but are no longer in submission)
        $deletedAreaIds = $existingStocks->keys()->diff($submittedAreaIds);
        foreach ($deletedAreaIds as $areaId) {
            if ($userLocationIds !== null) {
                $area = \App\Models\Area::find($areaId);
                if (! $area) {
                    continue;
                }

                $areaLocationIds = $area->locations()
                    ->pluck('locations.id')
                    ->toArray();

                $hasPermission = ! empty(array_intersect($areaLocationIds, $userLocationIds));

                if (! $hasPermission) {
                    abort(403, "Non hai il permesso di eliminare lo stock dall'area #{$areaId}");
                }
            }
            // Remove the stock, or empty it when a document still references it
            $existingStocks[$areaId]->deleteOrEmpty();
            $stocksChanged = true;
        }

        $groupedStocks = collect($stocks)->groupBy('area_id');

        foreach ($groupedStocks as $areaId => $stocksForArea) {
            $existingStock = $existingStocks->get($areaId);

            // Calculate total quantity for this area from the request
            $totalQuantity = $stocksForArea->sum('quantity');
            $lastDescription = $stocksForArea->last()['description'] ?? '';

            // Check if stock actually changed (for existing stocks)
            $hasChanged = false;
            if ($existingStock) {
                // Normalize values for comparison
                $oldQty = (int) $existingStock->quantity;
                $newQty = (int) $totalQuantity;
                $oldDesc = trim($existingStock->description ?? '');
                $newDesc = trim($lastDescription);

                $hasChanged = ($oldQty !== $newQty) || ($oldDesc !== $newDesc);
            } else {
                // New stock creation
                $hasChanged = true;
            }

            // Only check permission if stock has changed
            if ($hasChanged && $userLocationIds !== null) {
                $area = \App\Models\Area::find($areaId);
                if (! $area) {
                    abort(403, "Area #{$areaId} non trovata");
                }

                $areaLocationIds = $area->locations()
                    ->pluck('locations.id')
                    ->toArray();

                // Check if any of the area's locations are in user's allowed locations
                $hasPermission = ! empty(array_intersect($areaLocationIds, $userLocationIds));

                if (! $hasPermission) {
                    abort(403, "Non hai il permesso di modificare lo stock nell'area {$area->name}");
                }
            }

            if ($existingStock) {
                // Only update if changed
                if ($hasChanged) {
                    $existingStock->update([
                        'quantity' => $totalQuantity,
                        'description' => $lastDescription ?: $existingStock->description,
                    ]);
                    $stocksChanged = true;
                }
            } else {
                // Create new stock if none exists
                Stock::create([
                    'record_id' => $record->id,
                    'area_id' => $areaId,
                    'quantity' => $totalQuantity,
                    'description' => $lastDescription,
                ]);
                $stocksChanged = true;
            }
        }

        return $stocksChanged;
    }

    /**
     * Get areas filtered by user permissions, plus inject areas from existing stock
     * so combos can display them (frontend will show as read-only).
     */
    private function getAreasWithStockAreas(Record $record)
    {
        // Get areas user has access to (filtered)
        $userAreas = Area::filterByAdminRoles()->defaulLocationsAreas()
            ->with('locations')
            ->get();

        // Get area IDs from existing stock
        $stockAreaIds = Stock::where('record_id', $record->id)
            ->pluck('area_id')
            ->unique();

        // Get any missing areas (areas in stock but not in user's filtered list)
        $userAreaIds = $userAreas->pluck('id');
        $missingAreaIds = $stockAreaIds->diff($userAreaIds);

        if ($missingAreaIds->isNotEmpty()) {
            // Fetch the missing areas and merge them
            $missingAreas = Area::whereIn('id', $missingAreaIds)
                ->with('locations')
                ->get();

            return $userAreas->merge($missingAreas);
        }

        return $userAreas;
    }

    /**
     * Print barcode for the specified record.
     */
    public function printBarcode(Record $record, BarcodeLabelService $barcodeLabels)
    {
        return view('pdf.barcode', $barcodeLabels->forRecord($record));
    }

    /**
     * Show the history for the specified record.
     */
    public function history(Record $record)
    {
        // Get active WholesaleIns with their records for this specific record
        $wholesaleIns = DB::table('wholesale_ins')
            ->join('wholesale_in_records', 'wholesale_ins.id', '=', 'wholesale_in_records.wholesale_in_id')
            ->join('suppliers', 'wholesale_ins.supplier_id', '=', 'suppliers.id')
            ->where('wholesale_in_records.record_id', $record->id)
            ->where('wholesale_ins.status', 1) // Active only
            ->select(
                'wholesale_ins.id',
                'wholesale_ins.created_at',
                'suppliers.name as supplier_name',
                'wholesale_in_records.quantity',
                DB::raw("'WholesaleIn' as type")
            )
            ->get();

        // Get active WholesaleOuts with their records for this specific record
        $wholesaleOuts = DB::table('wholesale_outs')
            ->join('wholesale_out_records', 'wholesale_outs.id', '=', 'wholesale_out_records.wholesale_out_id')
            ->join('customers', 'wholesale_outs.customer_id', '=', 'customers.id')
            ->where('wholesale_out_records.record_id', $record->id)
            ->where('wholesale_outs.status', 1) // Active only
            ->select(
                'wholesale_outs.id',
                'wholesale_outs.created_at',
                DB::raw("TRIM(CONCAT_WS(' ', customers.name, customers.last_name)) as customer_name"),
                'wholesale_out_records.quantity',
                DB::raw("'WholesaleOut' as type")
            )
            ->get();

        // Get Sales (one row per SaleRecord) for this specific record
        $sales = DB::table('sales')
            ->join('sale_records', 'sales.id', '=', 'sale_records.sale_id')
            ->leftJoin('locations', 'sales.location_id', '=', 'locations.id')
            ->where('sale_records.record_id', $record->id)
            ->select(
                'sales.id',
                DB::raw('sales.date as created_at'),
                'locations.name as location_name',
                'sales.remote_customer_name',
                'sale_records.quantity',
                DB::raw("'Sale' as type")
            )
            ->get();

        // Merge and sort by creation date
        $history = $wholesaleIns->concat($wholesaleOuts)->concat($sales)
            ->sortByDesc('created_at')
            ->values();

        return Inertia::render('Record/History', [
            'record' => new RecordResource($record),
            'history' => $history,
        ]);
    }
}
