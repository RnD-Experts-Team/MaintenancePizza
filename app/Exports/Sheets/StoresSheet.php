<?php

namespace App\Exports\Sheets;

use App\Models\Store;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class StoresSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Stores';
    }

    public function collection(): Collection
    {
        return Store::query()->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Store Number', 'Created By', 'Created At', 'Updated At'];
    }

    /**
     * @param  Store  $store
     * @return array<int, mixed>
     */
    public function map($store): array
    {
        return [
            $store->id,
            $store->store_number,
            $store->created_by,
            (string) $store->created_at,
            (string) $store->updated_at,
        ];
    }
}
