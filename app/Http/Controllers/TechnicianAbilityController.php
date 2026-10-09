<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTechnicianRatingRequest;
use App\Http\Requests\UpsertTechnicianAbilityRequest;
use App\Models\Issue;
use App\Models\Technician;
use App\Services\TechnicianAbilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Technician abilities: stars, notes and the "call first" pin.
 *
 *   GET    /technician-abilities?issue_id=                 overall + per-issue entries
 *   PUT    /technicians/{technician}/abilities/{issue}     rate on one issue (replaces)
 *   DELETE /technicians/{technician}/abilities/{issue}     forget that entry
 *   PATCH  /technicians/{technician}/rating                the overall rating
 *
 * Writes that move a pin say whose it was in `moved_from`, so the screen can
 * tell the coordinator.
 */
class TechnicianAbilityController extends Controller
{
    public function __construct(private TechnicianAbilityService $abilities)
    {
    }

    public function index(Request $request)
    {
        $request->validate(['issue_id' => ['nullable', 'integer']]);

        return ['data' => $this->abilities->board($request->filled('issue_id') ? $request->integer('issue_id') : null)];
    }

    public function update(UpsertTechnicianAbilityRequest $request, Technician $technician, Issue $issue)
    {
        $result = $this->abilities->setForIssue($technician, $issue, $request->validated());

        return [
            'data' => $result['ability'] ? $this->abilities->presentAbility($result['ability']) : null,
            'moved_from' => $this->abilities->presentMover($result['moved_from']),
        ];
    }

    public function destroy(Technician $technician, Issue $issue): Response
    {
        $this->abilities->removeForIssue($technician, $issue);

        return response()->noContent();
    }

    public function rating(UpdateTechnicianRatingRequest $request, Technician $technician)
    {
        $result = $this->abilities->setOverall($technician, $request->validated());

        return [
            'data' => $this->abilities->presentOverall($result['technician']),
            'moved_from' => $this->abilities->presentMover($result['moved_from']),
        ];
    }
}
