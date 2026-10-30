<?php

namespace App\Services\MigrationV2;

use Illuminate\Support\Facades\DB;

/**
 * Old "part usages" are split by what they really were.
 *
 * - Taken from storage: already in the stock ledger as a Used movement; the
 *   movement now names the ticket issue it was used on.
 * - Bought by a technician with his own money: a technician purchase. If an
 *   old pay sheet paid it back, PayToPayments already recorded it (and linked
 *   the parts line that paid); otherwise it waits as unpaid.
 * - Bought by us: in v2 everything we pay is a payment, so it becomes a Parts
 *   payment to the supplier "Unknown (migrated)", dated the day it was
 *   recorded, for the store of its ticket.
 */
final class PartUsagesToV2 extends Converter
{
    public function run(): void
    {
        DB::table('part_usages')->orderBy('id')->chunkById(200, function ($usages) {
            foreach ($usages as $usage) {
                DB::transaction(fn () => $this->convert($usage));
            }
        });
    }

    private function convert(object $usage): void
    {
        $issues = DB::table('part_ticket_issue')->where('part_usage_id', $usage->id)->pluck('ticket_issue_id')->map(fn ($id) => (int) $id)->all();
        $firstIssue = $issues[0] ?? null;

        if ($usage->source === 'from_storage') {
            DB::table('stock_movements')->where('part_usage_id', $usage->id)->whereNull('ticket_issue_id')->update(['ticket_issue_id' => $firstIssue]);
            if ($firstIssue !== null && ! LegacyMap::has('part_usages', (int) $usage->id, 'ticket_issues')) {
                $this->moveMorphs('App\\Models\\PartUsage', (int) $usage->id, 'App\\Models\\TicketIssue', $firstIssue);
                LegacyMap::put('part_usages', (int) $usage->id, 'ticket_issues', $firstIssue);
            }

            return;
        }

        // A pay sheet paid it back to a technician (PayToPayments recorded
        // the purchase), whatever `paid_by` says: it must not ALSO become a
        // payment to "Unknown (migrated)", or the money would count twice.
        if (LegacyMap::has('part_usages', (int) $usage->id, 'technician_purchases')) {
            $purchaseId = LegacyMap::get('part_usages', (int) $usage->id, 'technician_purchases');
            $this->moveMorphs('App\\Models\\PartUsage', (int) $usage->id, 'App\\Models\\TechnicianPurchase', (int) $purchaseId);

            if ($usage->paid_by !== 'technician' && ! LegacyMap::has('part_usages', (int) $usage->id, LegacyMap::SKIPPED)) {
                LegacyMap::put('part_usages', (int) $usage->id, LegacyMap::SKIPPED, 0);
                LegacyMap::report('parts', "Part usage #{$usage->id} said \"paid by us\" but a pay sheet paid it back to a technician; kept as that technician's purchase", ['part_usage_id' => $usage->id], 'warning');
            }

            return;
        }

        if ($usage->paid_by === 'technician') {
            if (LegacyMap::has('part_usages', (int) $usage->id, LegacyMap::SKIPPED)) {
                return;
            }

            $technicianId = $usage->paid_by_technician_id ?? $this->soleTechnician($issues, (int) $usage->id);
            if ($technicianId === null) {
                // Reported once; remembered so a second run does not again.
                LegacyMap::put('part_usages', (int) $usage->id, LegacyMap::SKIPPED, 0);

                return;
            }

            $purchaseId = (int) DB::table('technician_purchases')->insertGetId([
                'technician_id' => $technicianId,
                'ticket_issue_id' => $firstIssue,
                'part_id' => $usage->part_id,
                'description' => DB::table('parts')->where('id', $usage->part_id)->value('name') ?? 'Part',
                'quantity' => $usage->quantity,
                'total_cost' => $this->money($usage->cost),
                'bought_at' => $usage->created_at,
                'mistaken' => $usage->mistaken,
                'created_by' => $usage->created_by,
                'created_at' => $usage->created_at,
                'updated_at' => $usage->updated_at,
            ]);
            LegacyMap::put('part_usages', (int) $usage->id, 'technician_purchases', $purchaseId);
            $this->moveMorphs('App\\Models\\PartUsage', (int) $usage->id, 'App\\Models\\TechnicianPurchase', $purchaseId);

            return;
        }

        if (LegacyMap::has('part_usages', (int) $usage->id, 'payments')) {
            return;
        }

        [$storeId, $other] = $firstIssue === null ? [null, 'Unknown store'] : $this->storeOfIssue($firstIssue);

        $paymentId = (int) DB::table('payments')->insertGetId([
            'paid_at' => substr((string) $usage->created_at, 0, 10),
            'supplier_id' => $this->unknownSupplierId(),
            'total' => $this->money($usage->cost),
            'mistaken' => $usage->mistaken,
            'created_by' => $usage->created_by,
            'created_at' => $usage->created_at,
            'updated_at' => $usage->updated_at,
        ]);
        $lineId = (int) DB::table('payment_lines')->insertGetId([
            'payment_id' => $paymentId,
            'type' => 'parts',
            'store_id' => $storeId,
            'other_store' => $storeId === null ? ($other ?? 'Unknown store') : null,
            'description' => 'Bought for the job (recorded before v2)',
            'amount' => $this->money($usage->cost),
            'part_id' => $usage->part_id,
            'quantity' => $usage->quantity,
            'unit_cost' => $usage->unit_cost,
            'bought_from_supplier_id' => $this->unknownSupplierId(),
            'created_at' => $usage->created_at,
            'updated_at' => $usage->updated_at,
        ]);
        foreach ($issues as $issueId) {
            DB::table('payment_line_ticket_issue')->insertOrIgnore([
                'payment_line_id' => $lineId,
                'ticket_issue_id' => $issueId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        LegacyMap::put('part_usages', (int) $usage->id, 'payments', $paymentId);
        $this->moveMorphs('App\\Models\\PartUsage', (int) $usage->id, 'App\\Models\\Payment', $paymentId);
    }

    /** @param  list<int>  $issueIds */
    private function soleTechnician(array $issueIds, int $usageId): ?int
    {
        $ids = DB::table('technician_ticket_issue')->whereIn('ticket_issue_id', $issueIds)->distinct()->pluck('technician_id');

        if ($ids->count() !== 1) {
            LegacyMap::report('parts', "Part usage #{$usageId} was paid for by a technician but did not say which one; it was left out of the unpaid list", ['part_usage_id' => $usageId, 'ticket_issue_ids' => $issueIds], 'error');
        }

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
