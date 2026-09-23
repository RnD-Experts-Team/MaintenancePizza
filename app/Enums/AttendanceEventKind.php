<?php

namespace App\Enums;

/**
 * One thing that happened on the clock.
 *
 * The vocabulary is the coordinator's, not the database's: these are the eight
 * things somebody says out loud on the phone -- "he clocked in, he set off, he
 * arrived, he went for parts, he came back, he took a break, he came back, he
 * clocked out".
 *
 * Each kind opens or closes a span, and each span belongs to a BUCKET that pay
 * is computed from. Travel and parts-run are paid; break is not.
 */
enum AttendanceEventKind: string
{
    case ClockIn = 'clock_in';
    case ClockOut = 'clock_out';
    case TravelStart = 'travel_start';
    case TravelEnd = 'travel_end';
    case BreakStart = 'break_start';
    case BreakEnd = 'break_end';
    case PartsRunStart = 'parts_run_start';
    case PartsRunEnd = 'parts_run_end';

    /** Which bucket's span this event belongs to. */
    public function bucket(): string
    {
        return match ($this) {
            self::ClockIn, self::ClockOut => 'work',
            self::TravelStart, self::TravelEnd => 'travel',
            self::BreakStart, self::BreakEnd => 'break',
            self::PartsRunStart, self::PartsRunEnd => 'parts_run',
        };
    }

    /** Opens a span, or closes one. */
    public function opens(): bool
    {
        return match ($this) {
            self::ClockIn, self::TravelStart, self::BreakStart, self::PartsRunStart => true,
            default => false,
        };
    }

    /** What a coordinator would say out loud. */
    public function label(): string
    {
        return match ($this) {
            self::ClockIn => 'Clocked in',
            self::ClockOut => 'Clocked out',
            self::TravelStart => 'Started driving',
            self::TravelEnd => 'Arrived',
            self::BreakStart => 'Went on break',
            self::BreakEnd => 'Back from break',
            self::PartsRunStart => 'Left to get parts',
            self::PartsRunEnd => 'Back with parts',
        };
    }

    /** Does the span this belongs to earn money? */
    public function paid(): bool
    {
        return $this->bucket() !== 'break';
    }
}
