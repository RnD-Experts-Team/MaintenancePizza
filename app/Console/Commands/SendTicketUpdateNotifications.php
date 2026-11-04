<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxEventJob;
use App\Models\IssueInfoRequest;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\MaintenanceAnalyticsService;
use App\Services\MaintenanceEvents\MaintenanceEventFactory;
use App\Services\MaintenanceEvents\MaintenanceOutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                $lines = $this->lines($analytics->changes($ticket, $since, $now), $this->questions($ticket, $since, $now));

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
     * Questions the office asked the store in the window and that are still
     * open -- the one thing in the notification the Store Manager has to act
     * on, so it goes first.
     *
     * @return array<int, string>
     */
    private function questions(Ticket $ticket, Carbon $since, Carbon $until): array
    {
        return IssueInfoRequest::query()
            ->whereHas('ticketIssue', fn ($q) => $q->where('ticket_id', $ticket->id))
            ->where('mistaken', false)
            ->whereNull('answered_at')
            ->where('created_at', '>', $since)
            ->where('created_at', '<', $until)
            ->with('ticketIssue.issue')
            ->orderBy('id')
            ->get()
            ->map(fn (IssueInfoRequest $r) => ($r->ticketIssue?->displayTitle() ?: 'Issue').': the office asks "'.Str::limit($r->question, 120).'"')
            ->all();
    }

    /**
     * A few short lines: the office's open questions, each issue's status
     * move (first to last), then how many notes and files were added. Four
     * at most.
     *
     * @param  array{opened: bool, status_changes: array<int, array<string, mixed>>, notes: int, files: int}  $changes
     * @param  array<int, string>  $questions
     * @return array<int, string>
     */
    private function lines(array $changes, array $questions = []): array
    {
        $moves = [];
        foreach ($changes['status_changes'] as $change) {
            $title = $change['title'] ?: 'Issue';
            $moves[$title] ??= ['from' => $change['from'], 'to' => null];
            $moves[$title]['to'] = $change['to'];
        }

        $lines = $questions;
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
