<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when a movement would take a (part, location) below zero. Thrown from
 * inside StockService's transaction, so the whole write — including whatever
 * part usage triggered it — rolls back.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $partId,
        public readonly int $storageLocationId,
        public readonly string $requested,
        public readonly string $available,
        public readonly ?string $partName = null,
        public readonly ?string $locationName = null,
    ) {
        parent::__construct(sprintf(
            'Not enough stock: tried to take %s of %s from %s, which holds %s.',
            $requested,
            $partName ?? "part #{$partId}",
            $locationName ?? "location #{$storageLocationId}",
            $available,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'lines' => [$this->getMessage()],
            ],
            'context' => [
                'part_id' => $this->partId,
                'storage_location_id' => $this->storageLocationId,
                'requested' => $this->requested,
                'available' => $this->available,
            ],
        ], 422);
    }
}
