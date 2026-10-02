<?php

namespace App\Exports;

use App\Imports\RecordsImport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class RecordTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        // return RecordsImport::getBaseHeaders();

        $data = RecordsImport::getBaseHeaders();

        // Recupera le colonne area come nell'export principale
        $areaColumns = \App\Models\Location::with(['areas' => function ($q) {
            $q->orderBy('name');
        }])->get()->flatMap(function ($location) {
            // $areas = $location->areas->sortByDesc(fn ($area) => $area->id === $location->default_area_id ? 1 : 0);

            // return $areas->map(fn ($area) => $location->name.' - '.$area->name.($area->id === $location->default_area_id ? ' (default)' : '')
            // );

            $defaultArea = $location->areas->firstWhere('id', $location->default_area_id);

            if ($defaultArea) {
                return [$location->name.' - '.$defaultArea->name.' (default)'];
            }

            return [];

        })->values()->all();

        return array_merge($data, $areaColumns);

    }

    public function collection()
    {
        // We are generating a template, so no data rows are needed.
        // Return an empty collection.
        return collect([]);
    }
}
