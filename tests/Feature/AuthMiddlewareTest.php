<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\Attachment;
use App\Models\IssueStatusChange;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The real middleware chain, end to end: token verified against a faked
 * pizzasys, user logged in, and the acting user stamped on what they create.
 *
 * Every other HTTP test in this suite bypasses the middleware, which left
 * Auth::id() null and hid three fillable bugs that silently dropped
 * created_by (attachments, issue status changes, attendance events).
 */
class AuthMiddlewareTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    public function test_an_employee_token_is_refused_with_403_not_a_crash(): void
    {
        $this->fakeAuthServer();
        $this->tokenSubjectType = 'employee';

        $this->getJson('/api/tickets', $this->headers())->assertForbidden();
    }

    public function test_a_missing_token_is_401(): void
    {
        $this->fakeAuthServer();

        $this->getJson('/api/tickets')->assertUnauthorized();
    }

    public function test_attachments_on_a_note_record_who_uploaded_them(): void
    {
        Storage::fake('public');
        $user = $this->fakeAuthServer();
        [$store, $ticket, $issue] = $this->ticket();

        $this->post(
            "/api/stores/{$store->store_number}/tickets/{$ticket->id}/issues/{$issue->id}/notes",
            ['body' => 'Photo of the thermostat', 'files' => [UploadedFile::fake()->image('thermostat.jpg')]],
            $this->headers(),
        )->assertCreated()
            ->assertJsonPath('data.created_by', $user->id)
            ->assertJsonPath('data.attachments.0.created_by', $user->id);

        $this->assertSame($user->id, (int) Attachment::query()->sole()->created_by);
    }

    public function test_a_status_change_records_who_made_it(): void
    {
        $user = $this->fakeAuthServer();
        [$store, $ticket, $issue] = $this->ticket();

        $this->postJson(
            "/api/stores/{$store->store_number}/tickets/{$ticket->id}/issues/status",
            ['ticket_issue_ids' => [$issue->id], 'status' => IssueStatus::InProgress->value],
            $this->headers(),
        )->assertOk();

        $this->assertSame($user->id, (int) IssueStatusChange::query()->sole()->created_by);
    }

    /**
     * @return array{0: Store, 1: Ticket, 2: TicketIssue}
     */
    private function ticket(): array
    {
        $store = Store::factory()->create();
        $ticket = Ticket::factory()->for($store)->create();
        $issue = TicketIssue::factory()->for($ticket)->create();

        return [$store, $ticket, $issue];
    }
}
