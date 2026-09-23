<?php

namespace App\Exports\Sheets;

use App\Models\StorageLocation;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class StorageLocationsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Storage Locations';
    }

    public function collection(): Collection
    {
        return StorageLocation::withTrashed()->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Name', 'Code', 'Address', 'Created By', 'Created At', 'Deleted At'];
    }

    /**
     * @param  StorageLocation  $location
     * @return array<int, mixed>
     */
    public function map($location): array
    {
        return [
            $location->id,
            $location->name,
            $location->code,
            $location->address,
            $location->created_by,
            (string) $location->created_at,
            (string) $location->deleted_at,
        ];
    }
}
