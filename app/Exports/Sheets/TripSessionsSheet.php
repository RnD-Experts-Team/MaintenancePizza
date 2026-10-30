<?php

namespace App\Exports\Sheets;

use App\Models\TripEvent;
use App\Models\TripSession;
use App\Services\TripService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One row per clock-in → clock-out, with the minutes worked out the way the
 * trip page and the payments do (TripService::durations), and its events.
 */
class TripSessionsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Trip Sessions';
    }

    public function collection(): Collection
    {
        return TripSession::with(['technician', 'events', 'paymentLines'])->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Trip ID', 'Technician', 'Clock In', 'Clock Out', 'Using Car', 'Odometer In', 'Odometer Out',
            'Source', 'Work Minutes', 'Travel Minutes', 'Parts Run Minutes', 'Break Minutes', 'Paid Minutes',
            'Paid', 'Payment Line ID', 'Payment ID', 'Events', 'Mistaken', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  TripSession  $session
     * @return array<int, mixed>
     */
    public function map($session): array
    {
        $minutes = app(TripService::class)->durations($session);
        $line = $session->paymentLines->first();

        return [
            $session->id,
            $session->trip_id,
            $session->technician?->name,
            (string) $session->clock_in,
            (string) $session->clock_out,
            $session->using_car ? 'yes' : 'no',
            $session->odometer_in,
            $session->odometer_out,
            $session->source,
            $minutes['work'],
            $minutes['travel'],
            $minutes['parts_run'],
            $minutes['break'],
            $minutes['paid'],
            $line !== null ? 'yes' : 'no',
            $line?->id,
            $line?->payment_id,
            $session->events
                ->map(fn (TripEvent $e) => $e->kind->value . ' ' . $e->at . ($e->mistaken ? ' (mistaken)' : ''))
                ->implode('; '),
            $session->mistaken ? 'yes' : 'no',
            $session->created_by,
            (string) $session->created_at,
        ];
    }
}
