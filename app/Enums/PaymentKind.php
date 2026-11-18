<?php

namespace App\Enums;

/**
 * What a payment row is (docs/MAINTENANCE.md §4): money we paid, or money
 * that came back for a payment (a refund, with negative lines).
 */
enum PaymentKind: string
{
    case Payment = 'payment';
    case Refund = 'refund';
}
