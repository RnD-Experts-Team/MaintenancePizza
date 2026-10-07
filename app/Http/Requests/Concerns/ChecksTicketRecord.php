<?php

namespace App\Http\Requests\Concerns;

use App\Services\TicketIssueService;

/**
 * For the leaf routes under stores/{store}/tickets/{ticket}: the ticket must
 * be the store's, the record the URL names must be on that ticket, and a
 * reschedule must be its booking's. 404 otherwise -- those routes bind
 * records by id alone. On URLs without a ticket (catalog notes, the global
 * attendance routes) there is nothing to check.
 */
trait ChecksTicketRecord
{
    /** Route parameters that name a record reaching tickets through its issues. */
    private const TICKET_RECORD_PARAMETERS = ['assignment', 'diagnosis', 'attendanceEntry', 'partUsage', 'payEntry', 'warranty'];

    protected function checkTicketRecord(): void
    {
        $record = null;
        foreach (self::TICKET_RECORD_PARAMETERS as $parameter) {
            $record ??= $this->route($parameter);
        }

        app(TicketIssueService::class)->assertRecordOnTicket($this->route('store'), $this->route('ticket'), $record);

        $delay = $this->route('delay');
        if ($delay !== null) {
            abort_unless((int) $delay->assignment_id === (int) $this->route('assignment')?->id, 404);
        }
    }
}
