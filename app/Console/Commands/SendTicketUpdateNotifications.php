<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxEventJob;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\MaintenanceAnalyticsService;
use App\Services\MaintenanceEvents\MaintenanceEventFactory;
use App\Services\MaintenanceEvents\MaintenanceOutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendTicketUpdateNotifications extends Command
{
    protected $signature = 'tickets:send-update-notifications';

    protected $description = 'Tell each ticket\'s Store Managers what changed since they were last told';

    public function handle(MaintenanceAnalyticsService $analytics): int
    {
        $now = now();

        // Only tickets touched in the last day -- both lookups ride the
        // updated_at indexes, so closed and quiet tickets are never read.
        // A change older than a day that was never sent (the scheduler was
        // down that long) is stale news and is let go.
        $since = $now->copy()->subDay();
        $ticketIds = Ticket::query()->where('updated_at', '>', $since)->pluck('id')
            ->merge(TicketIssue::query()->where('updated_at', '>', $since)->distinct()->pluck('ticket_id'))
            ->unique()
            ->values();

        $tickets = Ticket::query()
            ->whereIn('id', $ticketIds)
            ->whereNotNull('store_id')
            ->with('store')
            ->withMax('ticketIssues as issues_updated_at', 'updated_at')
            ->get()
            ->filter(function (Ticket $ticket) {
                $told = $ticket->last_notified_at ?? $ticket->created_at;
                $issues = $ticket->getAttributes()['issues_updated_at'] ?? null;

                return $ticket->updated_at->greaterThan($told)
                    || ($issues !== null && Carbon::parse($issues)->greaterThan($told));
            });

        foreach ($tickets as $ticket) {
            DB::transaction(function () use ($ticket, $analytics, $now) {
                $since = $ticket->last_notified_at ?? $ticket->created_at;
                $lines = $this->lines($analytics->changes($ticket, $since, $now));

                // Nothing a Store Manager can see changed (a private note, a
                // pay sheet claiming the work): nothing to say.
                if ($lines !== []) {
                    $storeNumber = $ticket->store->store_number;

                    $this->recordEvent($this->notificationSubject(), [
                        'channels' => ['web'],
                        'roles' => ['Store Manager'],
                        'stores' => [$storeNumber],
                        'payload' => [
                            'type' => 'maintenance_ticket_updated',
                            'title' => "Ticket #{$ticket->id} for Store {$storeNumber} was updated",
                            'body' => implode(' · ', $lines),
                            'action_url' => "/dashboard/maintenance-tickets/{$ticket->id}?store={$storeNumber}",
                        ],
                    ]);
                }

                Ticket::query()->whereKey($ticket->id)->toBase()->update(['last_notified_at' => $now]);
            });
        }

        return self::SUCCESS;
    }

    /**
     * A few short lines: each issue's status move (first to last), then how
     * many notes and files were added. Four at most.
     *
     * @param  array{opened: bool, status_changes: array<int, array<string, mixed>>, notes: int, files: int}  $changes
     * @return array<int, string>
     */
    private function lines(array $changes): array
    {
        $moves = [];
        foreach ($changes['status_changes'] as $change) {
            $title = $change['title'] ?: 'Issue';
            $moves[$title] ??= ['from' => $change['from'], 'to' => null];
            $moves[$title]['to'] = $change['to'];
        }

        $lines = [];
        foreach ($moves as $title => $move) {
            if ($move['from'] !== $move['to']) {
                $lines[] = $move['from'] ? "{$title}: {$move['from']} → {$move['to']}" : "{$title}: {$move['to']}";
            }
        }

        $added = array_filter([
            $changes['notes'] > 0 ? $changes['notes'] . ($changes['notes'] === 1 ? ' new note' : ' new notes') : null,
            $changes['files'] > 0 ? $changes['files'] . ($changes['files'] === 1 ? ' new file' : ' new files') : null,
        ]);
        if ($added !== []) {
            $lines[] = implode(', ', $added);
        }

        if (count($lines) > 4) {
            $lines = [...array_slice($lines, 0, 3), 'and ' . (count($lines) - 3) . ' more'];
        }

        return $lines;
    }

    private function recordEvent(string $subject, array $data): void
    {
        $factory = app(MaintenanceEventFactory::class);
        $outbox = app(MaintenanceOutboxService::class);

        $envelope = $factory->make($subject, $data);
        $row = $outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }

    private function notificationSubject(): string
    {
        return 'notifications.v1.notification.role.send';
    }
}
