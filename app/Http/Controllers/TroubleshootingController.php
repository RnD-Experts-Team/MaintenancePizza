<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Requests\StoreTroubleshootingFixRequest;
use App\Http\Requests\UpsertTroubleshootingGuideRequest;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Store;
use App\Models\TroubleshootingGuide;
use App\Models\TroubleshootingStep;
use App\Services\AttachmentService;
use App\Services\TroubleshootingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Troubleshooting guides -- several per issue, one per specific problem.
 *
 *   GET    /troubleshooting-guides                               the library
 *   GET    /issues/{issue}/troubleshooting                       one issue and all its guides
 *   POST   /issues/{issue}/troubleshooting-guides                add a guide
 *   PUT    /troubleshooting-guides/{guide}                       replace it (new version)
 *   DELETE /troubleshooting-guides/{guide}                       remove it
 *   POST   /troubleshooting-guides/{guide}/attachments           files for the whole guide
 *   DELETE /troubleshooting-guides/{guide}/attachments/{file}
 *   POST   /troubleshooting-steps/{step}/attachments             files for one step
 *   DELETE /troubleshooting-steps/{step}/attachments/{file}
 *   POST   /stores/{store}/troubleshooting-fixes                 "this fixed it" -- no ticket
 */
class TroubleshootingController extends Controller
{
    public function __construct(
        private TroubleshootingService $guides,
        private AttachmentService $attachments,
    ) {
    }

    public function index()
    {
        return ['data' => $this->guides->library()];
    }

    public function show(Issue $issue)
    {
        return ['data' => $this->guides->forIssue($issue)];
    }

    public function store(UpsertTroubleshootingGuideRequest $request, Issue $issue): JsonResponse
    {
        $data = $request->validated();
        $guide = $this->guides->create($issue, $data['title'], $data['steps'], $data['link_url'] ?? null);

        return response()->json(['data' => $this->guides->present($guide)], 201);
    }

    public function update(UpsertTroubleshootingGuideRequest $request, TroubleshootingGuide $guide)
    {
        $data = $request->validated();

        return ['data' => $this->guides->present($this->guides->update($guide, $data['title'], $data['steps'], $data['link_url'] ?? null))];
    }

    public function destroy(TroubleshootingGuide $guide): Response
    {
        $this->guides->remove($guide);

        return response()->noContent();
    }

    public function attachmentsStore(StoreAttachmentRequest $request, TroubleshootingGuide $guide): JsonResponse
    {
        return $this->addFiles($request, $guide);
    }

    public function attachmentsDestroy(TroubleshootingGuide $guide, Attachment $attachment): Response
    {
        $this->guides->removeFile($guide, $attachment);

        return response()->noContent();
    }

    public function stepAttachmentsStore(StoreAttachmentRequest $request, TroubleshootingStep $step): JsonResponse
    {
        return $this->addFiles($request, $step);
    }

    public function stepAttachmentsDestroy(TroubleshootingStep $step, Attachment $attachment): Response
    {
        $this->guides->removeFile($step, $attachment);

        return response()->noContent();
    }

    public function fix(StoreTroubleshootingFixRequest $request, Store $store): JsonResponse
    {
        $issue = Issue::query()->findOrFail((int) $request->validated('issue_id'));
        $guideId = $request->validated('troubleshooting_guide_id');
        $guide = $guideId === null ? null : TroubleshootingGuide::query()->findOrFail((int) $guideId);

        $fix = $this->guides->logFix($store, $issue, $guide);

        return response()->json(['data' => [
            'id' => $fix->id,
            'store_id' => $fix->store_id,
            'issue_id' => $fix->issue_id,
            'troubleshooting_guide_id' => $fix->troubleshooting_guide_id,
            'snapshot' => $fix->snapshot,
            'created_by' => $fix->created_by,
            'created_at' => $fix->created_at,
        ]], 201);
    }

    private function addFiles(StoreAttachmentRequest $request, Model $owner): JsonResponse
    {
        $created = $this->attachments->store($owner, (array) $request->file('files', []));

        return response()->json(['data' => array_map(fn ($a) => $this->attachments->present($a), $created)], 201);
    }
}
