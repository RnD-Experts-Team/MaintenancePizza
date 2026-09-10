<?php

namespace Database\Factories;

use App\Enums\TicketType;
use App\Models\Store;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'other_store' => null,
            'type' => TicketType::Normal->value,
        ];
    }

    /**
     * A ticket for a location that is not in the replicated store list.
     */
    public function otherStore(string $name = 'Off-system location'): static
    {
        return $this->state(fn () => ['store_id' => null, 'other_store' => $name]);
    }
}
