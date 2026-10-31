<?php

use App\Http\Controllers\IssueApprovalController;
use App\Http\Controllers\IssueInfoRequestController;
use App\Http\Controllers\IssueInspectionController;
use App\Http\Controllers\IssueScheduleController;
use App\Http\Controllers\IssueWorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Maintenance: the issue workflow (docs/MAINTENANCE.md §2)
|--------------------------------------------------------------------------
| Required from routes/api.php inside auth.token.store, within
|   Route::prefix('stores/{store}')->scopeBindings()
|     → Route::prefix('tickets/{ticket}')
| so paths here start at `issues/{ticketIssue}/...` and every parameter is
| bound as a child of the previous one. PARAMETER NAMES ARE LOAD-BEARING:
| Laravel looks the child up through Str::plural(Str::camel($param)) on the
| parent -- {infoRequest} → TicketIssue::infoRequests(), {inspection} →
| inspections(), {approval} → approvals(), {assignment} → assignments().
|
| Every step answers with the issue's full read ({data}, plus {trip_link}
| when it created a trip); a step that does not fit the issue's status is a
| 422 whose errors.status says why. The machine is IssueWorkflowService.
*/

Route::post('issues/{ticketIssue}/review', [IssueWorkflowController::class, 'review'])->name('tickets.issues.review');

Route::post('issues/{ticketIssue}/info-requests', [IssueInfoRequestController::class, 'store'])->name('tickets.issues.info-requests.store');
Route::post('issues/{ticketIssue}/info-requests/{infoRequest}/answer', [IssueInfoRequestController::class, 'answer'])->name('tickets.issues.info-requests.answer');

Route::post('issues/{ticketIssue}/inspections', [IssueInspectionController::class, 'store'])->name('tickets.issues.inspections.store');
Route::post('issues/{ticketIssue}/inspections/{inspection}/result', [IssueInspectionController::class, 'result'])->name('tickets.issues.inspections.result');

Route::post('issues/{ticketIssue}/approval-request', [IssueApprovalController::class, 'ask'])->name('tickets.issues.approval-request');
Route::post('issues/{ticketIssue}/approvals', [IssueApprovalController::class, 'store'])->name('tickets.issues.approvals.store');

Route::post('issues/{ticketIssue}/schedule', [IssueScheduleController::class, 'store'])->name('tickets.issues.schedule');
Route::post('issues/{ticketIssue}/schedules/{assignment}/move', [IssueScheduleController::class, 'move'])->name('tickets.issues.schedules.move');

Route::post('issues/{ticketIssue}/work-done', [IssueWorkflowController::class, 'workDone'])->name('tickets.issues.work-done');
Route::post('issues/{ticketIssue}/confirmation', [IssueWorkflowController::class, 'confirmation'])->name('tickets.issues.confirmation');
Route::post('issues/{ticketIssue}/close', [IssueWorkflowController::class, 'close'])->name('tickets.issues.close');
Route::post('issues/{ticketIssue}/jump', [IssueWorkflowController::class, 'jump'])->name('tickets.issues.jump');
