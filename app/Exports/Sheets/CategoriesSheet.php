<?php

namespace App\Exports\Sheets;

use App\Models\Category;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class CategoriesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Categories';
    }

    public function collection(): Collection
    {
        return Category::query()->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Name', 'Created By', 'Created At'];
    }

    /**
     * @param  Category  $category
     * @return array<int, mixed>
     */
    public function map($category): array
    {
        return [
            $category->id,
            $category->name,
            $category->created_by,
            (string) $category->created_at,
        ];
    }
}
