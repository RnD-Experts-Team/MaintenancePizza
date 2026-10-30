<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Models\IssueInfoRequest;
use App\Models\TicketIssue;
use App\Models\User;
use App\Services\MaintenanceEvents\IssueEventPublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "We need a photo of the label" (docs/MAINTENANCE-V2.md §2): the office asks
 * the store something during review, which makes it the store's turn, and the
 * answer -- typed by the store manager, or by the specialist after a phone
 * call (`via`) -- hands it back to the office.
 *
 * Files go on the info request (both the question's and the answer's), not
 * on the issue, so they stay next to what they answer.
 */
class InfoRequestService
{
    public const VIA = ['store', 'specialist'];

    public function __construct(
        private IssueWorkflowService $workflow,
        private AttachmentService $attachments,
        private IssueEventPublisher $events,
    ) {
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function ask(TicketIssue $issue, string $question, array $files, User $by): IssueInfoRequest
    {
        return DB::transaction(function () use ($issue, $question, $files, $by) {
            $issue = $this->workflow->lock($issue);
            $this->workflow->assertCan($issue, IssueWorkflowService::INFO_REQUEST);

            $request = IssueInfoRequest::query()->create([
                'ticket_issue_id' => $issue->id,
                'question' => $question,
                'asked_by' => $by->id,
            ]);

            $this->attachments->store($request, $files);

            if ($issue->status !== IssueStatus::WaitingOnStore) {
                TicketStatusService::changeIssueStatus($issue, IssueStatus::WaitingOnStore, 'Asked the store: '.Str::limit($question, 200));
            }

            $this->events->infoRequested($issue, $request);

            return $request;
        });
    }

    /**
     * Record the answer. The issue goes back to review once no other
     * question is still open -- two questions asked, one answered, is still
     * the store's turn.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function answer(TicketIssue $issue, IssueInfoRequest $request, string $answer, string $via, array $files, User $by): IssueInfoRequest
    {
        return DB::transaction(function () use ($issue, $request, $answer, $via, $files, $by) {
            $issue = $this->workflow->lock($issue);
            $this->workflow->assertCan($issue, IssueWorkflowService::ANSWER);

            $request = IssueInfoRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($request->mistaken) {
                $this->workflow->refuse('This question was marked as asked by mistake, so it needs no answer.', 'answer');
            }

            if ($request->answered_at !== null) {
                $this->workflow->refuse('This question was already answered on '.$request->answered_at->toDateString().'.', 'answer');
            }

            $request->fill([
                'answer' => $answer,
                'answered_via' => $via,
                'answered_by' => $by->id,
                'answered_at' => now(),
            ])->save();

            $this->attachments->store($request, $files);

            $stillOpen = IssueInfoRequest::query()
                ->where('ticket_issue_id', $issue->id)
                ->where('mistaken', false)
                ->whereNull('answered_at')
                ->exists();

            if ($issue->status === IssueStatus::WaitingOnStore && !$stillOpen) {
                $who = $via === 'specialist' ? 'The specialist recorded the store\'s answer' : 'The store answered';
                TicketStatusService::changeIssueStatus($issue, IssueStatus::InReview, $who.': '.Str::limit($answer, 200));
            }

            $this->events->infoAnswered($issue, $request);

            return $request;
        });
    }
}
