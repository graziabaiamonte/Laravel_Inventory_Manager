<?php

namespace App\Http\Controllers;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Enums\RecordsImportEnum;
use App\Exports\RecordTemplateExport;
use App\Facades\Flash;
use App\Http\Requests\RecordsImportRequest;
use App\Http\Resources\FormatResource;
use App\Http\Resources\RecordsImportRecordResource;
use App\Http\Resources\RecordsImportResource;
use App\Imports\RecordsImport as RecordImport;
use App\Models\Format;
use App\Models\Record;
use App\Models\RecordsImport;
use App\Models\RecordsImportRecordTmp;
use App\Services\RecordDataTransformer;
use App\Services\RecordImportService;
use App\Traits\LogsToChannel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\QueryBuilder\QueryBuilder;

class RecordsImportController extends Controller
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'imports';
    }

    public function __construct(
        private RecordImportService $recordImportService,
        private RecordDataTransformer $dataTransformer
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $baseQuery = QueryBuilder::for(RecordsImport::class)->select('*')->orderBy('created_at', 'desc');

        return Inertia::render('Record/Import/Index', [
            'records_imports' => RecordsImportResource::collection(
                $baseQuery->paginate($this->perPage($request))
            ),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        return Inertia::render('Record/Import/ImportRecord', [
            'importedRecords' => $request->session()->get('importedRecords', []),
            'showDeleteAlert' => $request->session()->get('showDeleteAlert', false),
            'coverStatus' => CoverStatusEnum::getJsonValues(),
            'diskStatus' => DiskStatusEnum::getJsonValues(),
        ]);
    }

    public function downloadTemplate()
    {
        try {
            $this->logInfo('Template download requested');

            $export = new RecordTemplateExport;
            $this->logInfo('Export instance created', [
                'headers' => $export->headings(),
                'collection_count' => $export->collection()->count(),
            ]);

            return Excel::download($export, 'records_template.xlsx');
        } catch (\Exception $e) {
            $this->logError('Template download error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to generate template'], 500);
        }
    }

    public function store(RecordsImportRequest $request)
    {
        $validated = $request->validated();

        // Handle file upload and import processing
        if ($request->hasFile('file') && empty($validated['records'])) {
            return $this->handleFileImport($request);
        }

        // Create new draft import
        $recordImport = RecordsImport::create(['draft' => 1]);

        // Process each record
        foreach ($validated['records'] as $record) {
            $this->recordImportService->createOrUpdateTmpRecord($record, $recordImport);
        }

        return redirect()
            ->route('records-import.edit', $recordImport->id)
            ->with('success', 'Importazione record avvenuta con successo.');
    }

    /**
     * Handle file import processing
     */
    private function handleFileImport(Request $request)
    {
        $importError = [];
        $importedRecords = [];

        try {

            $this->validateImportColumns($request->file('file'));

            $import = new RecordImport;
            $import->import($request->file('file'));
            $importedRecords = $import->getProcessedData();
        } catch (\Exception $e) {
            $importError['file'] = 'Errore nel file xlsx! '.$e->getMessage();
        }

        if (empty($importError)) {
            $storedFile = $request->file('file')->store('records/imports', 'local');
        } else {
            return redirect()->back()->withInput()->withErrors($importError);
        }

        return redirect()->back()->withInput()->with('importedRecords', $importedRecords);
    }

    /**
     *  Make sure that the columns in your Excel file are not capitalized.
     *
     * @throws \Exception
     */
    private function validateImportColumns($file): void
    {
        $forbiddenUppercaseColumns = [
            'cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q',
            'purchase_price', 'wholesale_price', 'retail_price',
            'discount', 'iva', 'supplier', 'condition_disk', 'condition_cover',
            'comments', 'description', 'soft_delete', 'delete', 'd_delete',
            'release_id (discogs)', 'created_at', 'last_sale',
        ];

        // Leggi solo la prima riga (header) del file
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(
            \PhpOffice\PhpSpreadsheet\IOFactory::identify($file)
        );
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file);
        $worksheet = $spreadsheet->getActiveSheet();

        // Ottieni i nomi delle colonne (prima riga)
        $headerRow = $worksheet->rangeToArray('A1:'.$worksheet->getHighestColumn().'1')[0];

        $uppercaseColumnsFound = [];

        foreach ($headerRow as $column) {
            if ($column === null || trim($column) === '') {
                continue;
            }

            $normalizedColumn = strtolower(trim($column));

            // Se la colonna è nella lista proibita E è completamente in maiuscolo
            if (in_array($normalizedColumn, $forbiddenUppercaseColumns)) {
                // Controlla se è in maiuscolo
                if ($column === strtoupper($column) && $column !== $normalizedColumn) {
                    $uppercaseColumnsFound[] = "'{$column}' (deve essere: '{$normalizedColumn}')";
                }
            }
        }

        if (! empty($uppercaseColumnsFound)) {
            throw new \Exception(
                "Il file contiene colonne in MAIUSCOLO che devono essere in minuscolo:\n".
                implode(', ', $uppercaseColumnsFound)."\n\n".
                'Scarica il template corretto e riprova.'
            );
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(RecordsImport $recordsImport)
    {
        return Inertia::render('Record/Import/Edit', [
            'record_import' => RecordsImportResource::make($recordsImport),
            'draft_status' => RecordsImportEnum::getJsonValues(),
            'records' => RecordsImportRecordResource::collection(
                RecordsImportRecordTmp::where('records_import_id', $recordsImport->id)
                    ->with(['artist', 'format', 'label', 'record'])
                    ->get()
            ),
            'formats' => FormatResource::collection(Format::where('status', 1)->get()),
            'coverStatus' => CoverStatusEnum::getJsonValues(),
            'diskStatus' => DiskStatusEnum::getJsonValues(),
        ]);
    }

    /**
     * Transform relationship objects to strings for validation compatibility
     */
    private function transformRelationshipsForValidation(array $data): array
    {
        return $this->dataTransformer->transformRelationshipsForValidation($data);
    }

    /**
     * Update records while in draft mode
     */
    private function updateRecordsInDraftMode(array $validatedRecords, array $originalRecords, RecordsImport $recordsImport): void
    {
        foreach ($validatedRecords as $index => $recordData) {
            $originalRecordData = $originalRecords[$index] ?? $recordData;

            if (isset($recordData['id'])) {
                // Update existing temporary record
                $this->updateExistingTmpRecord($recordData, $originalRecordData, $recordsImport);
            } else {
                // Create new temporary record for manually added records
                $this->createManuallyAddedTmpRecord($recordData, $originalRecordData, $recordsImport);
            }
        }
    }

    /**
     * Update existing temporary record
     */
    private function updateExistingTmpRecord(array $recordData, array $originalRecordData, RecordsImport $recordsImport): void
    {
        $tmpRecord = RecordsImportRecordTmp::where('id', $recordData['id'])
            ->where('records_import_id', $recordsImport->id)
            ->first();

        if ($tmpRecord) {
            $updateData = $this->dataTransformer->buildUpdateData($recordData, $originalRecordData);
            if (! empty($updateData)) {
                $tmpRecord->update($updateData);
            }
        }
    }

    /**
     * Create temporary record for manually added records
     */
    private function createManuallyAddedTmpRecord(array $recordData, array $originalRecordData, RecordsImport $recordsImport): void
    {
        $existingRecord = $this->recordImportService->findExistingRecord($recordData);
        $recordId = $existingRecord?->id;

        if (! $recordId) {
            // Create new temporary record
            $tmpData = $this->dataTransformer->buildNewRecordData($recordData, $originalRecordData, $recordsImport->id);
            RecordsImportRecordTmp::create($tmpData);
        } else {
            // Handle existing record
            $existingRecordTmp = RecordsImportRecordTmp::where('record_id', $recordId)->first();
            $tmpData = $this->dataTransformer->buildNewRecordData($recordData, $originalRecordData, $recordsImport->id, $recordId);

            if (! $existingRecordTmp) {
                RecordsImportRecordTmp::create($tmpData);
            } else {
                $existingRecordTmp->update($tmpData);
            }
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(RecordsImportRequest $request, RecordsImport $recordsImport)
    {
        // Store original data for relationship processing
        $originalData = $request->all();

        // Transform relationship objects to strings for validation compatibility
        $transformedData = $this->transformRelationshipsForValidation($originalData);
        $request->merge($transformedData);

        $validated = $request->validated();
        $oldDraft = $recordsImport->draft;
        $newDraft = $request->input('draft');

        // Handle updating individual records while in draft mode
        if ($recordsImport->isEditable() && isset($validated['records']) && is_array($validated['records'])) {
            $this->updateRecordsInDraftMode($validated['records'], $originalData['records'] ?? [], $recordsImport);
        } elseif (! $recordsImport->isEditable() && isset($validated['records'])) {
            return redirect()->back()->withErrors(['records' => 'Cannot edit records after import has been published.']);
        }

        $recordsImport->update(['draft' => $newDraft]);

        // Publish import if transitioning from draft to published
        if ($oldDraft == 1 && $newDraft == 0) {
            $result = $this->recordImportService->publishImport($recordsImport);

            // Warn about records that received auto-generated barcodes (both barcode and cat_number were empty)
            if (! empty($result['auto_barcode_records'])) {
                $lines = array_map(function ($record) {
                    $editUrl = route('record.edit', $record->id);

                    return "<a href=\"{$editUrl}\" target=\"_blank\">{$record->rr_uid}</a> - {$record->title}";
                }, $result['auto_barcode_records']);

                Flash::warning(
                    'I seguenti record hanno ricevuto un barcode generato automaticamente (uguale a RRID) perché privi di barcode e cat#. '
                    .'Puoi modificarli per inserire un cat# e rimuovere il barcode automatico:<br>'
                    .implode('<br>', $lines)
                );
            }

            // Warn about duplicate barcodes/cat_numbers found during publish
            if (! empty($result['duplicate_warnings'])) {
                Flash::warning(implode('<br>', $result['duplicate_warnings']));
            }

            return redirect()
                ->route('records-import.index')
                ->with('success', 'Importazione records avvenuta con successo.');
        }

        return redirect()
            ->route('records-import.edit', $recordsImport->id)
            ->with('success', 'Importazione records avvenuta con successo.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(?RecordsImport $recordsImport, Request $request)
    {
        if ($request->ids) {
            $this->bulkDeleteImports($request->ids);

            return back()->with('success', 'Importazioni eliminate con successo');
        }

        $recordsImport->delete();

        return redirect()->route('records-import.index')->with('success', 'Importazione eliminata con successo.');
    }

    /**
     * Bulk delete imports
     */
    private function bulkDeleteImports(array $ids): void
    {
        foreach ($ids as $id) {
            $recordImport = RecordsImport::find(intval($id));
            if ($recordImport) {
                $recordImport->delete();
            }
        }
    }

    /**
     * Update a single record in the temporary import table (only allowed while draft)
     */
    public function updateRecord(RecordsImportRequest $request, RecordsImport $recordsImport, RecordsImportRecordTmp $record)
    {
        if (! $recordsImport->isEditable()) {
            return response()->json(['error' => 'Cannot edit records after import has been published'], 403);
        }

        if ($record->records_import_id !== $recordsImport->id) {
            return response()->json(['error' => 'Record does not belong to this import'], 403);
        }

        // Transform relationship objects to strings for validation compatibility
        $originalData = $request->all();
        $transformedData = $this->transformRelationshipsForValidation($originalData);
        $request->merge($transformedData);

        $validated = $request->validated();
        $updateData = $this->dataTransformer->buildUpdateData($validated, $originalData);

        if (! empty($updateData)) {
            $record->update($updateData);
        }

        return response()->json([
            'success' => true,
            'record' => new RecordsImportRecordResource($record->fresh(['artist', 'format', 'label', 'record'])),
            'message' => 'Record updated successfully',
        ]);
    }

    /**
     * Delete a single record from the temporary import table (only allowed while draft)
     */
    public function deleteRecord(Request $request, RecordsImport $recordsImport, RecordsImportRecordTmp $record)
    {
        // Only allow deletion if the import is still a draft
        if (! $recordsImport->isEditable()) {
            return response()->json(['error' => 'Cannot delete records after import has been published'], 403);
        }

        // Verify the record belongs to this import
        if ($record->records_import_id !== $recordsImport->id) {
            return response()->json(['error' => 'Record does not belong to this import'], 403);
        }

        $record->delete();

        return redirect()
            ->back()
            ->with([
                'success' => 'Record temporaneo eliminato con successo!',
            ]);
    }

    /**
     * Add a new record to the temporary import table (only allowed while draft)
     */
    public function addRecord(RecordsImportRequest $request, RecordsImport $recordsImport)
    {
        if (! $recordsImport->isEditable()) {
            return response()->json(['error' => 'Cannot add records after import has been published'], 403);
        }

        // Transform relationship objects to strings for validation compatibility
        $originalData = $request->all();
        $transformedData = $this->transformRelationshipsForValidation($originalData);
        $request->merge($transformedData);

        $validated = $request->validated();

        // Create the new record in temporary table
        $tmpData = $this->dataTransformer->buildNewRecordData(
            $validated,
            $originalData,
            $recordsImport->id,
            $validated['record_id'] ?? null
        );

        // Override for_sale_on_discogs for new records
        $tmpData['for_sale_on_discogs'] = 1;

        $newRecord = RecordsImportRecordTmp::create($tmpData);

        return response()->json([
            'success' => true,
            'record' => new RecordsImportRecordResource($newRecord->fresh(['artist', 'format', 'label', 'record'])),
            'message' => 'Record added successfully',
        ]);
    }
}
