<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Store;
use App\Services\TicketIssueService;
use Illuminate\Http\Request;

/**
 * "Show me this store's last Oven tickets."
 *
 * GET /api/stores/{store}/issues/{issue}/history
 *   ?exclude_ticket=123   leave out the ticket being looked at
 *   &open_only=1          only tickets where this issue is still open
 *   &per_page=10&page=2
 */
class IssueHistoryController extends Controller
{
    public function __construct(private TicketIssueService $issues)
    {
    }

    public function index(Request $request, Store $store, Issue $issue)
    {
        $validated = $request->validate([
            'exclude_ticket' => ['nullable', 'integer'],
            'open_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->issues->history(
            $store,
            $issue,
            isset($validated['exclude_ticket']) ? (int) $validated['exclude_ticket'] : null,
            (bool) ($validated['open_only'] ?? false),
            (int) ($validated['per_page'] ?? 10),
        );
    }
}
