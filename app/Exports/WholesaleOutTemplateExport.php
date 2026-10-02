<?php

namespace App\Exports;

use App\Imports\WholesaleOutImport;
use App\Models\Area;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class WholesaleOutTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        $baseHeaders = WholesaleOutImport::getBaseHeaders();

        // Fetch active area names to be used as additional column headers
        // Convert to snake_case and lowercase to match import expectations
        // $areaHeaders = Area::where('status', 1)->get()->map(function ($area) {
        //     return \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($area->name));
        // })->toArray();

        $areaHeaders = Area::defaultWarehouseAreas()->get()->map(function ($area) {
            return \Illuminate\Support\Str::snake(\Illuminate\Support\Str::lower($area->name));
        })->toArray();

        return array_merge($baseHeaders, $areaHeaders);
    }

    public function collection()
    {
        // We are generating a template, so no data rows are needed.
        // Return an empty collection.
        return collect([]);
    }
}
