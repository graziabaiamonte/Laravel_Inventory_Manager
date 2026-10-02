<?php

namespace App\Exports;

use App\Imports\WholesaleInImport;
use App\Models\Area;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class WholesaleInTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function headings(): array
    {
        $baseHeaders = WholesaleInImport::getBaseHeaders();

        // Fetch active area names and normalize them for better XLSX parsing robustness
        // $areaHeaders = Area::where('status', 1)
        //     ->pluck('name')
        //     ->map(fn ($name) => Str::snake(Str::lower($name)))
        //     ->toArray();

        $areaHeaders = Area::defaulLocationsAreas()->pluck('name')
            ->map(fn ($name) => Str::snake(Str::lower($name)))
            ->toArray();

        return array_merge($baseHeaders, $areaHeaders);
    }

    public function collection()
    {
        // We are generating a template, so no data rows are needed.
        // Return an empty collection.
        return collect([]);
    }
}
