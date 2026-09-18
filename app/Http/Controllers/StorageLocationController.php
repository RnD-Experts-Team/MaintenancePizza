<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStorageLocationRequest;
use App\Models\StorageLocation;
use App\Models\StorageSlot;
use App\Http\Requests\StoreStorageSlotRequest;
use App\Services\StorageLocationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorageLocationController extends Controller
{
    public function __construct(private StorageLocationService $locations) {}

    public function index(Request $request)
    {
        return $this->locations->list($request->query('trashed'), $request->integer('per_page', 15));
    }

    public function store(StoreStorageLocationRequest $request)
    {
        return response()->json(['data' => $this->locations->create($request->validated())], 201);
    }

    public function destroy(StorageLocation $storageLocation): Response
    {
        $this->locations->delete($storageLocation);

        return response()->noContent();
    }

    public function restore(StorageLocation $storageLocation)
    {
        return ['data' => $this->locations->restore($storageLocation)];
    }

    /* ------------------------------------------------------------- Slots */

    /**
     * Where inside this location things sit.
     *
     * A location answers "Storage A"; a slot answers "shelf C, section 5". Each
     * location defines its own, so a van and a depot do not have to share a
     * vocabulary. Slots do not split the stock count -- they record where a part
     * lives, which is a findability question rather than an accounting one.
     */
    public function slotsIndex(Request $request, StorageLocation $storageLocation)
    {
        return ['data' => $this->locations->slots($storageLocation, $request->boolean('trashed'))];
    }

    public function slotsStore(StoreStorageSlotRequest $request, StorageLocation $storageLocation)
    {
        return response()->json(
            ['data' => $this->locations->createSlot($storageLocation, $request->validated())],
            201
        );
    }

    /** Slots ARE editable, unlike locations -- a mislabelled shelf is not worth
     *  retiring and recreating. */
    public function slotsUpdate(
        StoreStorageSlotRequest $request,
        StorageLocation $storageLocation,
        StorageSlot $storageSlot
    ) {
        return ['data' => $this->locations->updateSlot($storageSlot, $request->validated())];
    }

    public function slotsDestroy(StorageLocation $storageLocation, StorageSlot $storageSlot): Response
    {
        $this->locations->deleteSlot($storageSlot);

        return response()->noContent();
    }

}
