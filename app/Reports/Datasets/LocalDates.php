<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

/**
 * Timestamps are stored in UTC (config/app.php); a report's day is the New
 * York one. CONVERT_TZ needs MySQL's time zone tables loaded, or every date is
 * NULL.
 */
trait LocalDates
{
    private static function localDate(string $column): string
    {
        return "DATE(CONVERT_TZ({$column}, 'UTC', 'America/New_York'))";
    }

    /** Minutes, fractions kept, from one stored datetime to another. */
    private static function minutesBetween(string $from, string $to): string
    {
        return "(UNIX_TIMESTAMP({$to}) - UNIX_TIMESTAMP({$from})) / 60";
    }
}
