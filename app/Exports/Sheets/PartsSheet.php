<?php

namespace App\Exports\Sheets;

use App\Models\Part;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class PartsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Parts (Catalog)';
    }

    public function collection(): Collection
    {
        return Part::withTrashed()->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Name', 'Created By', 'Created At', 'Deleted At'];
    }

    /**
     * @param  Part  $part
     * @return array<int, mixed>
     */
    public function map($part): array
    {
        return [
            $part->id,
            $part->name,
            $part->created_by,
            (string) $part->created_at,
            (string) $part->deleted_at,
        ];
    }
}
