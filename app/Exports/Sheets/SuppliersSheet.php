<?php

namespace App\Exports\Sheets;

use App\Models\Supplier;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class SuppliersSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Suppliers';
    }

    public function collection(): Collection
    {
        return Supplier::withTrashed()->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Name', 'Phone', 'Website', 'Description', 'Created By', 'Created At', 'Deleted At'];
    }

    /**
     * @param  Supplier  $supplier
     * @return array<int, mixed>
     */
    public function map($supplier): array
    {
        return [
            $supplier->id,
            $supplier->name,
            $supplier->phone,
            $supplier->website,
            $supplier->description,
            $supplier->created_by,
            (string) $supplier->created_at,
            (string) $supplier->deleted_at,
        ];
    }
}
