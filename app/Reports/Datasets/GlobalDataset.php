<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\ReportDataset;

/**
 * A dataset about something this service keeps for the whole company (storage,
 * stock, payments, a technician's clock), not for a store. Its grain never
 * holds `store`, and pizzasys gives it a head-only rule, so a `stores` list in
 * the body cannot narrow it: the store filter is `1 IN (1)`, always true.
 */
abstract class GlobalDataset extends ReportDataset
{
    public function storeColumn(): string
    {
        return '1';
    }

    public function storeKeys(array $codes): array
    {
        return [1];
    }
}
