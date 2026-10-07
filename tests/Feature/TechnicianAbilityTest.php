<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Technician;
use App\Models\TechnicianIssueAbility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Who is good at what: 1-5 stars and notes per issue and overall, and the
 * "call first" pin -- one technician per issue, one overall.
 */
class TechnicianAbilityTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Issue $oven;

    private Technician $ahmed;

    private Technician $bilal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
        $this->oven = Issue::factory()->create(['title' => 'Oven']);
        $this->ahmed = Technician::factory()->create(['name' => 'Ahmed']);
        $this->bilal = Technician::factory()->create(['name' => 'Bilal']);
    }

    private function rate(Technician $technician, array $body, ?Issue $issue = null): \Illuminate\Testing\TestResponse
    {
        $issue ??= $this->oven;

        return $this->putJson("/api/technicians/{$technician->id}/abilities/{$issue->id}", $body, $this->headers());
    }

    public function test_a_technician_is_rated_on_an_issue_and_the_board_shows_it(): void
    {
        $this->rate($this->ahmed, ['rating' => 4, 'notes' => '  Knows the deck ovens  '])
            ->assertOk()
            ->assertJsonPath('data.technician_id', $this->ahmed->id)
            ->assertJsonPath('data.issue_id', $this->oven->id)
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.notes', 'Knows the deck ovens')
            ->assertJsonPath('data.call_first', false)
            ->assertJsonPath('data.editor.name', 'Dana Whitfield')
            ->assertJsonPath('moved_from', null);

        $this->getJson('/api/technician-abilities', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data.by_issue')
            ->assertJsonPath('data.by_issue.0.rating', 4)
            ->assertJsonCount(0, 'data.overall');
    }

    public function test_pinning_a_second_technician_moves_the_issue_pin(): void
    {
        $this->rate($this->ahmed, ['rating' => 5, 'call_first' => true])->assertOk()->assertJsonPath('data.call_first', true);

        $this->rate($this->bilal, ['rating' => 3, 'call_first' => true])
            ->assertOk()
            ->assertJsonPath('data.call_first', true)
            ->assertJsonPath('moved_from.id', $this->ahmed->id)
            ->assertJsonPath('moved_from.name', 'Ahmed');

        $ahmed = TechnicianIssueAbility::query()->where('technician_id', $this->ahmed->id)->firstOrFail();
        $this->assertFalse((bool) $ahmed->call_first, 'Ahmed lost the pin');
        $this->assertSame(5, $ahmed->rating, 'but kept his stars');

        // Re-saving the holder moves nothing.
        $this->rate($this->bilal, ['rating' => 4, 'call_first' => true])->assertOk()->assertJsonPath('moved_from', null);
    }

    public function test_a_pin_on_another_issue_is_left_alone(): void
    {
        $sink = Issue::factory()->create(['title' => 'Sink']);
        $this->rate($this->ahmed, ['call_first' => true], $sink)->assertOk();

        $this->rate($this->bilal, ['call_first' => true])->assertOk()->assertJsonPath('moved_from', null);

        $this->assertSame(2, TechnicianIssueAbility::query()->where('call_first', true)->count());
    }

    public function test_an_entry_that_was_only_the_pin_goes_away_when_the_pin_moves(): void
    {
        $this->rate($this->ahmed, ['call_first' => true])->assertOk();
        $this->rate($this->bilal, ['call_first' => true])->assertOk();

        $this->assertDatabaseMissing('technician_issue_abilities', ['technician_id' => $this->ahmed->id]);
    }

    public function test_an_empty_entry_is_removed_and_delete_forgets_one(): void
    {
        $this->rate($this->ahmed, ['rating' => 2])->assertOk();
        $this->rate($this->ahmed, ['rating' => null, 'notes' => '   '])->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseCount('technician_issue_abilities', 0);

        $this->rate($this->ahmed, ['rating' => 2])->assertOk();
        $this->deleteJson("/api/technicians/{$this->ahmed->id}/abilities/{$this->oven->id}", [], $this->headers())->assertNoContent();
        $this->assertDatabaseCount('technician_issue_abilities', 0);
    }

    public function test_stars_must_be_one_to_five(): void
    {
        foreach ([0, 6, 'great', 3.5] as $bad) {
            $this->rate($this->ahmed, ['rating' => $bad])->assertUnprocessable()->assertJsonValidationErrors('rating');
        }

        $this->patchJson("/api/technicians/{$this->ahmed->id}/rating", ['rating' => 9], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rating');
    }

    public function test_the_overall_rating_and_the_goat_pin(): void
    {
        $this->patchJson("/api/technicians/{$this->ahmed->id}/rating", ['rating' => 5, 'notes' => 'Our man', 'call_first' => true], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.technician_id', $this->ahmed->id)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.notes', 'Our man')
            ->assertJsonPath('data.call_first', true)
            ->assertJsonPath('data.editor.name', 'Dana Whitfield')
            ->assertJsonPath('moved_from', null);

        // Partial: only the stars change.
        $this->patchJson("/api/technicians/{$this->ahmed->id}/rating", ['rating' => 4], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.notes', 'Our man')
            ->assertJsonPath('data.call_first', true);

        $this->patchJson("/api/technicians/{$this->bilal->id}/rating", ['call_first' => true], $this->headers())
            ->assertOk()
            ->assertJsonPath('moved_from.name', 'Ahmed');

        $this->assertNull($this->ahmed->fresh()->call_first);
        $this->assertTrue($this->bilal->fresh()->call_first);

        $board = $this->getJson('/api/technician-abilities', $this->headers())->assertOk()->json('data.overall');
        $this->assertSame([$this->ahmed->id, $this->bilal->id], array_column($board, 'technician_id'));
    }

    public function test_the_issue_filter_narrows_the_per_issue_list(): void
    {
        $sink = Issue::factory()->create(['title' => 'Sink']);
        $this->rate($this->ahmed, ['rating' => 4])->assertOk();
        $this->rate($this->ahmed, ['rating' => 2], $sink)->assertOk();

        $this->getJson("/api/technician-abilities?issue_id={$sink->id}", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data.by_issue')
            ->assertJsonPath('data.by_issue.0.issue_id', $sink->id);
    }

    public function test_deleting_a_technician_clears_their_pins_but_keeps_their_stars(): void
    {
        $this->rate($this->ahmed, ['rating' => 5, 'call_first' => true])->assertOk();
        $this->patchJson("/api/technicians/{$this->ahmed->id}/rating", ['rating' => 5, 'call_first' => true], $this->headers())->assertOk();

        $this->deleteJson("/api/technicians/{$this->ahmed->id}", [], $this->headers())->assertNoContent();

        $board = $this->getJson('/api/technician-abilities', $this->headers())->assertOk()->json('data');
        $this->assertSame([], $board['overall'], 'a deleted technician is not on the board');
        $this->assertSame([], $board['by_issue']);

        $this->postJson("/api/technicians/{$this->ahmed->id}/restore", [], $this->headers())->assertOk();

        $board = $this->getJson('/api/technician-abilities', $this->headers())->assertOk()->json('data');
        $this->assertSame(5, $board['overall'][0]['rating']);
        $this->assertFalse($board['overall'][0]['call_first']);
        $this->assertSame(5, $board['by_issue'][0]['rating']);
        $this->assertFalse($board['by_issue'][0]['call_first']);

        // The pin is free for someone else straight away.
        $this->rate($this->bilal, ['call_first' => true])->assertOk()->assertJsonPath('moved_from', null);
    }

    public function test_ratings_never_appear_in_the_technician_list(): void
    {
        $this->patchJson("/api/technicians/{$this->ahmed->id}/rating", ['rating' => 1, 'notes' => 'Never again'], $this->headers())->assertOk();

        $row = collect($this->getJson('/api/technicians', $this->headers())->assertOk()->json('data'))
            ->firstWhere('id', $this->ahmed->id);

        foreach (['rating', 'rating_notes', 'call_first'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
        $this->assertStringNotContainsString('Never again', json_encode($row));
    }

    public function test_the_technician_edit_route_cannot_set_a_rating(): void
    {
        $this->patchJson("/api/technicians/{$this->ahmed->id}", ['name' => 'Ahmed S.', 'rating' => 5, 'call_first' => true], $this->headers())
            ->assertOk();

        $this->assertNull($this->ahmed->fresh()->rating);
        $this->assertNull($this->ahmed->fresh()->call_first);
    }

    public function test_the_database_allows_one_pin_per_issue(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        foreach ([$this->ahmed, $this->bilal] as $technician) {
            $ability = new TechnicianIssueAbility(['technician_id' => $technician->id, 'issue_id' => $this->oven->id]);
            $ability->call_first = true;
            $ability->save();
        }
    }

    public function test_the_database_allows_one_overall_pin(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        foreach ([$this->ahmed, $this->bilal] as $technician) {
            $technician->forceFill(['call_first' => true])->save();
        }
    }
}
