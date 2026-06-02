<?php

namespace App\Exports\Sheets;

use App\Models\AssignmentDelay;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class AssignmentDelaysSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Assignment Delays';
    }

    public function collection(): Collection
    {
        return AssignmentDelay::query()->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Assignment ID', 'Old Date', 'Old Hour', 'New Date', 'New Hour', 'Reason', 'Created By', 'Created At'];
    }

    /**
     * @param  AssignmentDelay  $delay
     * @return array<int, mixed>
     */
    public function map($delay): array
    {
        return [
            $delay->id,
            $delay->assignment_id,
            $delay->old_date?->toDateString(),
            $delay->old_hour,
            $delay->new_date?->toDateString(),
            $delay->new_hour,
            $delay->reason,
            $delay->created_by,
            (string) $delay->created_at,
        ];
    }
}
