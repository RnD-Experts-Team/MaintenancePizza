<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Store;
use App\Models\TicketIssue;
use App\Models\TroubleshootingFix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Troubleshooting guides -- several per issue, files on every step -- and how
 * a store's troubleshooting ends: fixed (logged, no ticket), or tried / none
 * of the guides matched, which unlocks opening the ticket.
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

    /**
     * @param  array<int, string|array{id?: int, body: string}>  $steps
     * @return array<string, mixed>
     */
    private function addGuide(string $title = "Won't heat", array $steps = ['Check the breaker', 'Relight the pilot'], ?Issue $issue = null): array
    {
        return $this->postJson('/api/issues/'.($issue ?? $this->oven)->id.'/troubleshooting-guides', [
            'title' => $title,
            'steps' => $this->steps($steps),
            'link_url' => 'https://example.com/oven.pdf',
        ], $this->headers())->assertCreated()->json('data');
    }

    /**
     * @param  array<int, string|array{id?: int, body: string}>  $steps
     * @return array<string, mixed>
     */
    private function editGuide(int $guideId, array $steps, string $title = "Won't heat"): array
    {
        return $this->putJson("/api/troubleshooting-guides/{$guideId}", [
            'title' => $title,
            'steps' => $this->steps($steps),
        ], $this->headers())->assertOk()->json('data');
    }

    /**
     * @param  array<int, string|array{id?: int, body: string}>  $steps
     * @return array<int, array{id?: int, body: string}>
     */
    private function steps(array $steps): array
    {
        return array_map(fn ($s) => is_string($s) ? ['body' => $s] : $s, $steps);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function openTicket(array $line): TestResponse
    {
        return $this->postJson("/api/stores/{$this->store->store_number}/tickets", [
            'issues' => [array_merge(['issue_id' => $this->oven->id, 'priority' => 'high', 'description' => 'Cold'], $line)],
        ], $this->headers());
    }

    public function test_an_issue_has_several_guides_each_versioned(): void
    {
        $heat = $this->addGuide();
        $door = $this->addGuide("Door won't close", ['Clear the hinge']);

        $this->assertSame(1, $heat['version']);
        $this->assertSame(['Check the breaker', 'Relight the pilot'], array_column($heat['steps'], 'body'));

        $edited = $this->editGuide($heat['id'], ['Check the breaker', '  ', 'Call the MOS']);
        $this->assertSame(2, $edited['version']);
        $this->assertSame(['Check the breaker', 'Call the MOS'], array_column($edited['steps'], 'body'), 'blank steps are dropped');

        $page = $this->getJson("/api/issues/{$this->oven->id}/troubleshooting", $this->headers())->assertOk()->json('data');
        $this->assertSame('Oven', $page['title']);
        $this->assertSame(["Door won't close", "Won't heat"], array_column($page['guides'], 'title'), 'alphabetical');
        $this->assertSame($door['id'], $page['guides'][0]['id']);

        $this->getJson('/api/troubleshooting-guides', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Oven')
            ->assertJsonPath('data.0.guides.1.steps_count', 2)
            ->assertJsonPath('data.0.guides.1.version', 2);

        $this->getJson('/api/issues?per_page=100', $this->headers())
            ->assertOk()->assertJsonPath('data.0.troubleshooting_guides_count', 2);

        $this->deleteJson("/api/troubleshooting-guides/{$door['id']}", [], $this->headers())->assertNoContent();
        $this->getJson("/api/issues/{$this->oven->id}/troubleshooting", $this->headers())->assertJsonCount(1, 'data.guides');
    }

    public function test_a_guide_needs_a_title_steps_and_a_real_link(): void
    {
        $url = "/api/issues/{$this->oven->id}/troubleshooting-guides";
        $this->postJson($url, ['steps' => [['body' => 'x']]], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson($url, ['title' => 'x', 'steps' => [['body' => ' ']]], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('steps');
        $this->postJson($url, ['title' => 'x', 'steps' => [['body' => 'x']], 'link_url' => 'javascript:alert(1)'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('link_url');
    }

    public function test_steps_keep_their_files_across_edits_and_removed_steps_lose_theirs(): void
    {
        $guide = $this->addGuide();
        [$breaker, $pilot] = $guide['steps'];

        $breakerFile = $this->post("/api/troubleshooting-steps/{$breaker['id']}/attachments",
            ['files' => [UploadedFile::fake()->image('breaker.jpg')]], $this->headers())
            ->assertCreated()->json('data.0.id');
        $pilotFile = $this->post("/api/troubleshooting-steps/{$pilot['id']}/attachments",
            ['files' => [UploadedFile::fake()->image('pilot.jpg')]], $this->headers())
            ->assertCreated()->json('data.0.id');

        // Reworded, reordered, the pilot step dropped, a new step added.
        $edited = $this->editGuide($guide['id'], [
            'Unplug it for a minute',
            ['id' => $breaker['id'], 'body' => 'Check the breaker panel'],
        ]);

        $this->assertSame(['Unplug it for a minute', 'Check the breaker panel'], array_column($edited['steps'], 'body'));
        $this->assertSame($breaker['id'], $edited['steps'][1]['id'], 'the kept step keeps its id');
        $this->assertSame('breaker.jpg', $edited['steps'][1]['attachments'][0]['original_name'], '... and its files');
        $this->assertSame([], $edited['steps'][0]['attachments']);

        $this->assertNotNull(Attachment::withTrashed()->find($pilotFile)->deleted_at, "a removed step's files are soft-deleted");
        $this->assertNull(Attachment::query()->find($breakerFile)->deleted_at);
    }

    public function test_a_step_id_from_another_guide_is_refused(): void
    {
        $mine = $this->addGuide();
        $theirs = $this->addGuide('Other', ['x'], Issue::factory()->create());

        $this->putJson("/api/troubleshooting-guides/{$mine['id']}", [
            'title' => "Won't heat",
            'steps' => [['id' => $theirs['steps'][0]['id'], 'body' => 'Stolen']],
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('steps.0.id');
    }

    public function test_files_are_added_and_removed_only_from_their_own_guide_or_step(): void
    {
        $guide = $this->addGuide();
        $other = $this->addGuide('Other', ['x'], Issue::factory()->create());

        $guideFile = $this->post("/api/troubleshooting-guides/{$guide['id']}/attachments",
            ['files' => [UploadedFile::fake()->image('manual.jpg')]], $this->headers())
            ->assertCreated()->json('data.0.id');
        $stepFile = $this->post("/api/troubleshooting-steps/{$guide['steps'][0]['id']}/attachments",
            ['files' => [UploadedFile::fake()->image('breaker.jpg')]], $this->headers())
            ->assertCreated()->json('data.0.id');

        $this->getJson("/api/issues/{$this->oven->id}/troubleshooting", $this->headers())
            ->assertJsonPath('data.guides.0.attachments.0.original_name', 'manual.jpg')
            ->assertJsonPath('data.guides.0.steps.0.attachments.0.original_name', 'breaker.jpg');

        $this->deleteJson("/api/troubleshooting-guides/{$other['id']}/attachments/{$guideFile}", [], $this->headers())->assertNotFound();
        $this->deleteJson("/api/troubleshooting-steps/{$other['steps'][0]['id']}/attachments/{$stepFile}", [], $this->headers())->assertNotFound();
        $this->deleteJson("/api/troubleshooting-steps/{$guide['steps'][0]['id']}/attachments/{$guideFile}", [], $this->headers())
            ->assertNotFound('a guide file is not a step file');

        $this->deleteJson("/api/troubleshooting-steps/{$guide['steps'][0]['id']}/attachments/{$stepFile}", [], $this->headers())->assertNoContent();
        $this->deleteJson("/api/troubleshooting-guides/{$guide['id']}/attachments/{$guideFile}", [], $this->headers())->assertNoContent();
    }

    public function test_deleting_a_guide_soft_deletes_its_files(): void
    {
        $guide = $this->addGuide();
        $stepFile = $this->post("/api/troubleshooting-steps/{$guide['steps'][0]['id']}/attachments",
            ['files' => [UploadedFile::fake()->image('breaker.jpg')]], $this->headers())->json('data.0.id');

        $this->deleteJson("/api/troubleshooting-guides/{$guide['id']}", [], $this->headers())->assertNoContent();

        $this->assertNotNull(Attachment::withTrashed()->find($stepFile)->deleted_at);
    }

    public function test_troubleshooting_is_required_when_the_issue_has_guides(): void
    {
        $this->addGuide();

        $refused = $this->openTicket([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issues.0.troubleshooting']);
        $this->assertSame(
            'Go through the troubleshooting for Oven before opening a ticket.',
            $refused->json('errors')['issues.0.troubleshooting'][0],
        );

        $this->openTicket(['troubleshooting' => 'fixed'])->assertUnprocessable()->assertJsonValidationErrors(['issues.0.troubleshooting']);
        $this->openTicket(['troubleshooting' => 'tried'])->assertCreated();
        $this->openTicket(['troubleshooting' => 'none_match'])->assertCreated();
    }

    public function test_issues_without_guides_and_other_issues_are_never_gated(): void
    {
        $this->openTicket([])->assertCreated();
        $this->postJson("/api/stores/{$this->store->store_number}/tickets", [
            'issues' => [['other_title' => 'Door', 'priority' => 'low', 'description' => 'Squeaks']],
        ], $this->headers())->assertCreated();
    }

    public function test_a_named_guide_must_be_the_issues_own_and_current(): void
    {
        $guide = $this->addGuide();
        $foreign = $this->addGuide('Other', ['x'], Issue::factory()->create());

        $this->openTicket(['troubleshooting' => 'tried', 'troubleshooting_guide_id' => $foreign['id']])
            ->assertUnprocessable()->assertJsonValidationErrors(['issues.0.troubleshooting_guide_id']);

        $this->editGuide($guide['id'], ['A brand new first step']); // now version 2
        $this->openTicket(['troubleshooting' => 'tried', 'troubleshooting_guide_id' => $guide['id'], 'troubleshooting_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['issues.0.troubleshooting_version']);
    }

    public function test_the_ticket_keeps_the_troubleshooting_as_it_was(): void
    {
        $guide = $this->addGuide();
        $this->addGuide("Door won't close", ['Clear the hinge']);
        $this->post("/api/troubleshooting-steps/{$guide['steps'][0]['id']}/attachments",
            ['files' => [UploadedFile::fake()->image('breaker.jpg')]], $this->headers());

        $ticketId = $this->openTicket(['troubleshooting' => 'tried', 'troubleshooting_guide_id' => $guide['id'], 'troubleshooting_version' => 1])
            ->assertCreated()->json('data.id');

        $this->editGuide($guide['id'], ['Something else entirely']); // edited afterwards

        $issue = TicketIssue::query()->where('ticket_id', $ticketId)->sole();
        $this->assertNotNull($issue->troubleshooting_confirmed_at);
        $this->assertSame('tried', $issue->troubleshooting_outcome);
        $this->assertSame($guide['id'], $issue->troubleshooting_guide_id);
        $snapshot = $issue->troubleshooting_snapshot;
        $this->assertSame("Won't heat", $snapshot['guide_title']);
        $this->assertSame(1, $snapshot['version']);
        $this->assertSame(['Check the breaker', 'Relight the pilot'], array_column($snapshot['steps'], 'body'));
        $this->assertSame('breaker.jpg', $snapshot['steps'][0]['files'][0]['name']);
        $this->assertSame(["Door won't close", "Won't heat"], array_column($snapshot['guides_shown'], 'title'));
        $this->assertSame($this->authUser->id, $snapshot['confirmed_by']);

        $this->getJson("/api/stores/{$this->store->store_number}/tickets/{$ticketId}/issues", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.troubleshooting_outcome', 'tried')
            ->assertJsonPath('data.0.troubleshooting_snapshot.steps.0.body', 'Check the breaker');

        // "Not fixed" is counted on the guide that was tried.
        $this->getJson("/api/issues/{$this->oven->id}/troubleshooting", $this->headers())
            ->assertJsonPath('data.guides.1.not_fixed_count', 1);
    }

    public function test_none_match_records_which_guides_were_shown(): void
    {
        $this->addGuide();

        $ticketId = $this->openTicket(['troubleshooting' => 'none_match'])->assertCreated()->json('data.id');

        $issue = TicketIssue::query()->where('ticket_id', $ticketId)->sole();
        $this->assertSame('none_match', $issue->troubleshooting_outcome);
        $this->assertNull($issue->troubleshooting_guide_id);
        $this->assertSame([], $issue->troubleshooting_snapshot['steps']);
        $this->assertSame(["Won't heat"], array_column($issue->troubleshooting_snapshot['guides_shown'], 'title'));
    }

    public function test_this_fixed_it_is_logged_without_a_ticket(): void
    {
        $guide = $this->addGuide();

        $this->postJson("/api/stores/{$this->store->store_number}/troubleshooting-fixes", [
            'issue_id' => $this->oven->id,
            'troubleshooting_guide_id' => $guide['id'],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.snapshot.outcome', 'fixed')
            ->assertJsonPath('data.snapshot.guide_title', "Won't heat");

        // Saying which guide is optional.
        $this->postJson("/api/stores/{$this->store->store_number}/troubleshooting-fixes", ['issue_id' => $this->oven->id], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.troubleshooting_guide_id', null);

        $fix = TroubleshootingFix::query()->whereNotNull('troubleshooting_guide_id')->sole();
        $this->assertSame($this->store->id, $fix->store_id);
        $this->assertSame($this->authUser->id, $fix->created_by);
        $this->assertSame(0, TicketIssue::query()->count(), 'no ticket was opened');

        $this->getJson("/api/issues/{$this->oven->id}/troubleshooting", $this->headers())
            ->assertJsonPath('data.guides.0.fixed_count', 1);
    }

    public function test_a_fix_names_only_a_guide_of_that_issue(): void
    {
        $foreign = $this->addGuide('Other', ['x'], Issue::factory()->create());

        $this->postJson("/api/stores/{$this->store->store_number}/troubleshooting-fixes", [
            'issue_id' => $this->oven->id,
            'troubleshooting_guide_id' => $foreign['id'],
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('troubleshooting_guide_id');
    }
}
