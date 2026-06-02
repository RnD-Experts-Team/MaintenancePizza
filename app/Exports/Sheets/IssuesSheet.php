<?php

namespace App\Exports\Sheets;

use App\Models\Issue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class IssuesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Issues (Catalog)';
    }

    public function collection(): Collection
    {
        return Issue::withTrashed()->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Title', 'Description', 'Created By', 'Created At', 'Deleted At'];
    }

    /**
     * @param  Issue  $issue
     * @return array<int, mixed>
     */
    public function map($issue): array
    {
        return [
            $issue->id,
            $issue->title,
            $issue->description,
            $issue->created_by,
            (string) $issue->created_at,
            (string) $issue->deleted_at,
        ];
    }
}
