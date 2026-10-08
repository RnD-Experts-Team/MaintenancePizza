<?php

namespace App\Exports\Sheets;

use App\Models\Technician;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class TechniciansSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Technicians';
    }

    public function collection(): Collection
    {
        return Technician::withTrashed()->with(['category', 'coverageStores'])->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Name', 'Category', 'Phone', 'Location', 'Covers', 'Coverage Notes', 'Created By', 'Created At', 'Deleted At'];
    }

    /**
     * @param  Technician  $technician
     * @return array<int, mixed>
     */
    public function map($technician): array
    {
        return [
            $technician->id,
            $technician->name,
            $technician->category?->name,
            $technician->phone,
            $technician->location,
            $technician->coverageStores->pluck('store_number')->implode(', '),
            $technician->coverage_notes,
            $technician->created_by,
            (string) $technician->created_at,
            (string) $technician->deleted_at,
        ];
    }
}
