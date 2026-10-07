<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Store;
use App\Models\TicketIssue;
use App\Models\TroubleshootingGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Troubleshooting guides, and the one tick a manager gives before a ticket
 * for that issue can be opened: "I read these steps and tried them".
 */
class TroubleshootingTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Issue $oven;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->fakeAuthServer();
        $this->oven = Issue::factory()->create(['title' => 'Oven']);
        $this->store = Store::factory()->create();
    }

    private function writeGuide(array $steps = ['Check the breaker', 'Relight the pilot'], ?string $link = 'https://example.com/oven.pdf'): array
    {
        return $this->putJson("/api/issues/{$this->oven->id}/troubleshooting", ['steps' => $steps, 'link_url' => $link], $this->headers())
            ->assertOk()->json('data');
    }

    private function openTicket(array $line): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/stores/{$this->store->store_number}/tickets", [
            'issues' => [array_merge(['issue_id' => $this->oven->id, 'priority' => 'high', 'description' => 'Cold'], $line)],
        ], $this->headers());
    }

    public function test_a_guide_is_written_versioned_read_listed_and_removed(): void
    {
        $first = $this->writeGuide();
        $this->assertSame(1, $first['version']);
        $this->assertSame(['Check the breaker', 'Relight the pilot'], $first['steps']);

        $second = $this->writeGuide(['Check the breaker', '  ', 'Call the MOS']);
        $this->assertSame(2, $second['version']);
        $this->assertSame(['Check the breaker', 'Call the MOS'], $second['steps'], 'blank steps are dropped');

        $this->getJson("/api/issues/{$this->oven->id}/troubleshooting", $this->headers())
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/troubleshooting-guides', $this->headers())
            ->assertOk()->assertJsonPath('data.0.title', 'Oven')->assertJsonPath('data.0.troubleshooting.version', 2);
        $this->getJson('/api/issues?per_page=100', $this->headers())
            ->assertOk()->assertJsonPath('data.0.troubleshooting.steps.1', 'Call the MOS');

        $this->deleteJson("/api/issues/{$this->oven->id}/troubleshooting", [], $this->headers())->assertNoContent();
        $this->getJson("/api/issues/{$this->oven->id}/troubleshooting", $this->headers())->assertOk()->assertJsonPath('data', null);
    }

    public function test_a_guide_needs_steps_and_a_real_link(): void
    {
        $this->putJson("/api/issues/{$this->oven->id}/troubleshooting", ['steps' => []], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('steps');
        $this->putJson("/api/issues/{$this->oven->id}/troubleshooting", ['steps' => ['x'], 'link_url' => 'javascript:alert(1)'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('link_url');
    }

    public function test_guide_files_are_added_and_removed_only_from_their_own_guide(): void
    {
        $guideId = $this->writeGuide()['id'];
        $fileId = $this->post("/api/troubleshooting-guides/{$guideId}/attachments",
            ['files' => [UploadedFile::fake()->image('breaker.jpg')]], $this->headers())
            ->assertCreated()->json('data.0.id');

        $this->getJson("/api/issues/{$this->oven->id}/troubleshooting", $this->headers())
            ->assertJsonPath('data.attachments.0.original_name', 'breaker.jpg');

        $other = TroubleshootingGuide::query()->create(['issue_id' => Issue::factory()->create()->id, 'steps' => ['x']]);
        $this->deleteJson("/api/troubleshooting-guides/{$other->id}/attachments/{$fileId}", [], $this->headers())->assertNotFound();

        $this->deleteJson("/api/troubleshooting-guides/{$guideId}/attachments/{$fileId}", [], $this->headers())->assertNoContent();
        $this->getJson("/api/issues/{$this->oven->id}/troubleshooting", $this->headers())->assertJsonPath('data.attachments', []);
    }

    public function test_the_tick_is_required_when_the_issue_has_a_guide(): void
    {
        $this->writeGuide();

        $refused = $this->openTicket([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issues.0.troubleshooting_confirmed']);
        $this->assertSame(
            'Read the troubleshooting steps for Oven and confirm you tried them before opening a ticket.',
            $refused->json('errors')['issues.0.troubleshooting_confirmed'][0],
        );

        $this->openTicket(['troubleshooting_confirmed' => true, 'troubleshooting_version' => 1])->assertCreated();
    }

    public function test_issues_without_a_guide_and_other_issues_are_never_gated(): void
    {
        $this->openTicket([])->assertCreated();
        $this->postJson("/api/stores/{$this->store->store_number}/tickets", [
            'issues' => [['other_title' => 'Door', 'priority' => 'low', 'description' => 'Squeaks']],
        ], $this->headers())->assertCreated();
    }

    public function test_a_tick_given_on_an_outdated_guide_is_refused(): void
    {
        $this->writeGuide();
        $this->writeGuide(['A brand new first step']); // now version 2

        $this->openTicket(['troubleshooting_confirmed' => true, 'troubleshooting_version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issues.0.troubleshooting_version']);
    }

    public function test_the_ticket_keeps_the_guide_as_it_was_when_confirmed(): void
    {
        $this->writeGuide();

        $ticketId = $this->openTicket(['troubleshooting_confirmed' => true, 'troubleshooting_version' => 1])
            ->assertCreated()->json('data.id');

        $this->writeGuide(['Something else entirely']); // edited afterwards

        $issue = TicketIssue::query()->where('ticket_id', $ticketId)->sole();
        $this->assertNotNull($issue->troubleshooting_confirmed_at);
        $this->assertSame(1, $issue->troubleshooting_snapshot['version']);
        $this->assertSame(['Check the breaker', 'Relight the pilot'], $issue->troubleshooting_snapshot['steps']);
        $this->assertSame($this->authUser->id, $issue->troubleshooting_snapshot['confirmed_by']);

        $this->getJson("/api/stores/{$this->store->store_number}/tickets/{$ticketId}/issues", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.troubleshooting_snapshot.steps.0', 'Check the breaker');
    }
}
