<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDailyPayEntryRequest;
use App\Models\DailyPayEntry;
use App\Services\DailyPayEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyPayEntryController extends Controller
{
    public function __construct(private DailyPayEntryService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'technician_ids',
            'store_ids',
            'date',
            'date_from',
            'date_to',
            'filled_by',
            'created_from',
            'created_to',
            'per_page',
            'sort',
            'dir',
        ]);

        return response()->json($this->service->list($filters));
    }

    public function store(StoreDailyPayEntryRequest $request): JsonResponse
    {
        [$lineFilesMap, $lineNoteFilesMap] = $this->extractLineFiles($request);

        return response()->json(
            ['data' => $this->service->create($request->validated(), $lineFilesMap, $lineNoteFilesMap)],
            201
        );
    }

    public function show(DailyPayEntry $dailyPayEntry): JsonResponse
    {
        return response()->json(['data' => $this->service->show($dailyPayEntry)]);
    }

    public function edit(StoreDailyPayEntryRequest $request, DailyPayEntry $dailyPayEntry): JsonResponse
    {
        [$lineFilesMap, $lineNoteFilesMap] = $this->extractLineFiles($request);

        return response()->json(
            ['data' => $this->service->edit($dailyPayEntry, $request->validated(), $lineFilesMap, $lineNoteFilesMap)]
        );
    }

    /**
     * Extract per-line and per-line-per-note files from the multipart request.
     *
     * @return array{array<int, array<int, \Illuminate\Http\UploadedFile>>, array<int, array<int, array<int, \Illuminate\Http\UploadedFile>>>}
     */
    private function extractLineFiles(Request $request): array
    {
        $lineFilesMap     = [];
        $lineNoteFilesMap = [];

        foreach ($request->input('lines', []) as $i => $line) {
            $lineFilesMap[$i] = (array) $request->file("lines.{$i}.files", []);

            foreach ($line['notes'] ?? [] as $ni => $_) {
                $lineNoteFilesMap[$i][$ni] = (array) $request->file("lines.{$i}.notes.{$ni}.files", []);
            }
        }

        return [$lineFilesMap, $lineNoteFilesMap];
    }
}
