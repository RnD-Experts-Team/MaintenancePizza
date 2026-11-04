<?php

namespace App\Exports\Sheets;

use App\Models\Trip;
use App\Models\TripStop;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/** One row per trip; its stops are summarised, its sessions are the next sheet. */
class TripsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Trips';
    }

    public function collection(): Collection
    {
        return Trip::with(['technician' => fn ($q) => $q->withTrashed(), 'stops.store', 'stops.ticketIssues'])
            ->withCount('sessions')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Technician', 'Status', 'Planned Date', 'Link Idle Hours', 'Last Used At', 'Closed At',
            'Instructions', 'Stops', 'Ticket Issue IDs', 'Session Count', 'Mistaken', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  Trip  $trip
     * @return array<int, mixed>
     */
    public function map($trip): array
    {
        return [
            $trip->id,
            $trip->technician?->name,
            $trip->status?->label(),
            $trip->planned_date?->toDateString(),
            $trip->link_idle_hours,
            (string) $trip->last_used_at,
            (string) $trip->closed_at,
            $trip->instructions,
            $trip->stops
                ->map(fn (TripStop $s) => ($s->store?->store_number ?? $s->other_store) . " ({$s->status}" . ($s->mistaken ? ', mistaken' : '') . ')')
                ->implode('; '),
            $trip->stops->flatMap(fn (TripStop $s) => $s->ticketIssues->pluck('id'))->unique()->implode(', '),
            $trip->sessions_count,
            $trip->mistaken ? 'yes' : 'no',
            $trip->created_by,
            (string) $trip->created_at,
        ];
    }
}
