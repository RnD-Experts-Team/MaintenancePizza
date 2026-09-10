<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDailyPayEntryRequest;
use App\Models\DailyPayEntry;
use App\Services\DailyPayEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

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
            'created_from',
            'created_to',
            'per_page',
            'sort',
            'dir',
        ]);
        $filters['filled_by'] = array_filter((array) $request->input('filled_by', []));

        return response()->json($this->service->list($filters));
    }

    public function store(StoreDailyPayEntryRequest $request): JsonResponse
    {
        [$paymentFiles, $paymentNoteFiles, $lineFiles, $lineNoteFiles] = $this->extractFiles($request);

        return response()->json(
            ['data' => $this->service->create($request->validated(), $paymentFiles, $paymentNoteFiles, $lineFiles, $lineNoteFiles)],
            201
        );
    }

    public function show(DailyPayEntry $dailyPayEntry): JsonResponse
    {
        return response()->json(['data' => $this->service->show($dailyPayEntry)]);
    }

    public function edit(StoreDailyPayEntryRequest $request, DailyPayEntry $dailyPayEntry): JsonResponse
    {
        [$paymentFiles, $paymentNoteFiles, $lineFiles, $lineNoteFiles] = $this->extractFiles($request);

        return response()->json(
            ['data' => $this->service->edit($dailyPayEntry, $request->validated(), $paymentFiles, $paymentNoteFiles, $lineFiles, $lineNoteFiles)]
        );
    }

    /**
     * Pull every level's files out of the multipart request, keyed by their
     * index in the payload.
     *
     * @return array{
     *     array<int, array<int, UploadedFile>>,
     *     array<int, array<int, array<int, UploadedFile>>>,
     *     array<int, array<int, array<int, UploadedFile>>>,
     *     array<int, array<int, array<int, array<int, UploadedFile>>>>
     * }
     */
    private function extractFiles(Request $request): array
    {
        $paymentFiles = [];
        $paymentNoteFiles = [];
        $lineFiles = [];
        $lineNoteFiles = [];

        foreach ($request->input('payments', []) as $p => $payment) {
            $paymentFiles[$p] = (array) $request->file("payments.{$p}.files", []);

            foreach ($payment['notes'] ?? [] as $n => $_) {
                $paymentNoteFiles[$p][$n] = (array) $request->file("payments.{$p}.notes.{$n}.files", []);
            }

            foreach ($payment['lines'] ?? [] as $l => $line) {
                $lineFiles[$p][$l] = (array) $request->file("payments.{$p}.lines.{$l}.files", []);

                foreach ($line['notes'] ?? [] as $n => $_) {
                    $lineNoteFiles[$p][$l][$n] = (array) $request->file("payments.{$p}.lines.{$l}.notes.{$n}.files", []);
                }
            }
        }

        return [$paymentFiles, $paymentNoteFiles, $lineFiles, $lineNoteFiles];
    }
}
