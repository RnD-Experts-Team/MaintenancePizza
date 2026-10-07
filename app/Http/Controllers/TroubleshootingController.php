<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Requests\UpsertTroubleshootingGuideRequest;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\TroubleshootingGuide;
use App\Services\AttachmentService;
use App\Services\TroubleshootingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Troubleshooting guides.
 *
 *   GET    /troubleshooting-guides                              the library
 *   GET    /issues/{issue}/troubleshooting                      one issue's guide (null when none)
 *   PUT    /issues/{issue}/troubleshooting                      create / replace it (new version)
 *   DELETE /issues/{issue}/troubleshooting                      remove it
 *   POST   /troubleshooting-guides/{guide}/attachments          add files
 *   DELETE /troubleshooting-guides/{guide}/attachments/{file}   remove one
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
        $guide = $issue->troubleshootingGuide()->with(['attachments.creator', 'editor'])->first();

        return ['data' => $this->guides->present($guide)];
    }

    public function update(UpsertTroubleshootingGuideRequest $request, Issue $issue)
    {
        $data = $request->validated();

        return ['data' => $this->guides->present($this->guides->upsert($issue, $data['steps'], $data['link_url'] ?? null))];
    }

    public function destroy(Issue $issue): Response
    {
        $this->guides->remove($issue);

        return response()->noContent();
    }

    public function attachmentsStore(StoreAttachmentRequest $request, TroubleshootingGuide $guide): JsonResponse
    {
        $created = $this->attachments->store($guide, (array) $request->file('files', []));

        return response()->json(['data' => array_map(fn ($a) => $this->attachments->present($a), $created)], 201);
    }

    public function attachmentsDestroy(TroubleshootingGuide $guide, Attachment $attachment): Response
    {
        $this->guides->removeFile($guide, $attachment);

        return response()->noContent();
    }
}
