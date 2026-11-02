<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\IssueInfoRequest;
use App\Models\User;
use App\Services\MaintenanceEvents\IssueEventPublisher;
use Illuminate\Http\UploadedFile;

/**
 * Questions to the store and their answers (docs/MAINTENANCE.md §2): asking
 * makes it the store's turn, the answer (from the store, or the specialist
 * after a phone call) hands it back to review.
 */
class InfoRequestWorkflowTest extends IssueWorkflowTestCase
{
    private function ask(string $question = 'Can you send a photo of the label?'): int
    {
        $this->act('info-requests', ['question' => $question])->assertOk();

        return (int) IssueInfoRequest::query()->latest('id')->value('id');
    }

    public function test_asking_the_store_waits_on_the_store_and_writes_the_outbox_event(): void
    {
        $this->putStatus(IssueStatus::InReview);

        $this->post($this->url('/info-requests'), [
            'question' => 'Can you send a photo of the label?',
            'files' => [UploadedFile::fake()->image('where.jpg')],
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'waiting_on_store')
            ->assertJsonPath('data.whose_turn', 'store')
            ->assertJsonPath('data.info_requests.0.question', 'Can you send a photo of the label?')
            ->assertJsonPath('data.info_requests.0.asked_by.name', 'Dana Whitfield')
            ->assertJsonPath('data.info_requests.0.answer', null)
            ->assertJsonPath('data.info_requests.0.attachments.0.original_name', 'where.jpg')
            ->assertJsonPath('data.workflow.next.0.action', 'answer');

        $events = $this->outbox(IssueEventPublisher::INFO_REQUESTED);
        $this->assertCount(1, $events);
        $this->assertSame('Can you send a photo of the label?', $events[0]->payload['data']['question']);
        $this->assertSame('03795-00001', $events[0]->payload['data']['issue']['store_number']);
    }

    public function test_a_store_manager_answers_and_the_issue_goes_back_to_review(): void
    {
        $this->putStatus(IssueStatus::InReview);
        $id = $this->ask();

        $manager = User::query()->create(['id' => 31, 'name' => 'Store Manager Mo', 'email' => 'mo@example.com']);
        $this->actAs($manager);

        $this->post($this->url("/info-requests/{$id}/answer"), [
            'answer' => 'Photo attached',
            'files' => [UploadedFile::fake()->image('label.jpg')],
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.info_requests.0.answer', 'Photo attached')
            ->assertJsonPath('data.info_requests.0.answered_by_name', null)
            ->assertJsonPath('data.info_requests.0.answered_by.name', 'Store Manager Mo')
            ->assertJsonPath('data.info_requests.0.attachments.0.original_name', 'label.jpg');

        $events = $this->outbox(IssueEventPublisher::INFO_ANSWERED);
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->payload['data']['answered_by_name']);
        $this->assertSame(31, $events[0]->payload['data']['answered_by']);
    }

    public function test_the_specialist_records_the_answer_given_on_the_phone(): void
    {
        $id = $this->ask();

        $this->act("info-requests/{$id}/answer", ['answer' => 'It is model X-200', 'answered_by_name' => '  Sara, by phone '])->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.info_requests.0.answered_by_name', 'Sara, by phone')
            ->assertJsonPath('data.info_requests.0.answered_by.id', $this->authUser->id);

        $this->assertStringStartsWith(
            'The store answered (Sara, by phone): It is model X-200',
            $this->issue->statusChanges()->latest('id')->value('reason'),
        );
    }

    public function test_with_two_questions_open_one_answer_keeps_it_the_stores_turn(): void
    {
        $first = $this->ask('Photo of the label?');
        $this->act('info-requests', ['question' => 'When does it fail?'])->assertOk();

        $this->act("info-requests/{$first}/answer", ['answer' => 'Sent'])->assertOk()
            ->assertJsonPath('data.status', 'waiting_on_store');
    }

    public function test_an_answered_question_cannot_be_answered_again(): void
    {
        $id = $this->ask();
        $this->act("info-requests/{$id}/answer", ['answer' => 'Sent'])->assertOk();

        $this->assertRefused($this->act("info-requests/{$id}/answer", ['answer' => 'Again']), 'answer');
    }

    public function test_asking_is_refused_once_the_work_is_scheduled(): void
    {
        $this->putStatus(IssueStatus::Scheduled);

        $this->assertRefused($this->act('info-requests', ['question' => 'x']))
            ->assertJsonPath('errors.status.0', 'You can ask the store a question while reviewing the issue, not while it is scheduled.');
        $this->assertSame(0, IssueInfoRequest::query()->count());
    }

    public function test_answering_is_refused_on_a_closed_issue(): void
    {
        $id = $this->ask();
        $this->putStatus(IssueStatus::Closed);

        $this->assertRefused($this->act("info-requests/{$id}/answer", ['answer' => 'late']));
    }

    public function test_a_question_from_another_issue_is_not_found(): void
    {
        $id = $this->ask();
        $other = \App\Models\TicketIssue::factory()->for($this->ticket)->create();

        $this->act("info-requests/{$id}/answer", ['answer' => 'x'], $other)->assertNotFound();
    }

    public function test_a_question_is_required(): void
    {
        $this->act('info-requests', [])->assertUnprocessable()->assertJsonValidationErrors(['question']);
    }
}
