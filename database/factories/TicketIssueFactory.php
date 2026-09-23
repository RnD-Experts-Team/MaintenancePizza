<?php

namespace Database\Factories;

use App\Enums\IssueStatus;
use App\Enums\Priority;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketIssue>
 */
class TicketIssueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'issue_id' => null,
            'other_title' => $this->faker->sentence(3),
            'priority' => Priority::Medium->value,
            'assigned_priority' => null,
            'description' => $this->faker->sentence(),
            'status' => IssueStatus::Pending->value,
            'parent_id' => null,
        ];
    }
}
