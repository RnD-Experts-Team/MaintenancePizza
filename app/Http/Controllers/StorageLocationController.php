<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStorageLocationRequest;
use App\Models\StorageLocation;
use App\Models\StoragePlaceLevel;
use App\Models\StoragePlaceValue;
use App\Http\Requests\StoragePlaceLevelRequest;
use App\Http\Requests\StoragePlaceValueRequest;
use App\Services\StorageLocationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorageLocationController extends Controller
{
    public function __construct(private StorageLocationService $locations)
    {
    }

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

    /* ------------------------------------------------- Place levels & values */

    /**
     * How this location addresses the space inside it.
     *
     * A location answers "Storage A"; its levels and their values answer
     * "shelf C, row 8, column 5". Each location declares its own, so a van and
     * a depot never have to share a vocabulary.
     *
     * None of this splits the stock count -- it records where a part lives,
     * which is a findability question rather than an accounting one.
     */
    public function placeLevelsIndex(Request $request, StorageLocation $storageLocation)
    {
        return ['data' => $this->locations->placeLevels($storageLocation)];
    }

    public function placeLevelsStore(StoragePlaceLevelRequest $request, StorageLocation $storageLocation)
    {
        return response()->json(
            ['data' => $this->locations->createPlaceLevel($storageLocation, $request->validated())],
            201
        );
    }

    /** Levels ARE editable, unlike locations -- a mislabelled level is not
     *  worth retiring and recreating along with all its values. */
    public function placeLevelsUpdate(
        StoragePlaceLevelRequest $request,
        StorageLocation $storageLocation,
        StoragePlaceLevel $placeLevel
    ) {
        return ['data' => $this->locations->updatePlaceLevel($placeLevel, $request->validated())];
    }

    public function placeLevelsDestroy(
        StorageLocation $storageLocation,
        StoragePlaceLevel $placeLevel
    ): Response {
        $this->locations->deletePlaceLevel($placeLevel);

        return response()->noContent();
    }

    public function placeValuesStore(
        StoragePlaceValueRequest $request,
        StorageLocation $storageLocation,
        StoragePlaceLevel $placeLevel
    ) {
        return response()->json(
            ['data' => $this->locations->createPlaceValue($placeLevel, $request->validated())],
            201
        );
    }

    public function placeValuesUpdate(
        StoragePlaceValueRequest $request,
        StorageLocation $storageLocation,
        StoragePlaceLevel $placeLevel,
        StoragePlaceValue $placeValue
    ) {
        return ['data' => $this->locations->updatePlaceValue($placeValue, $request->validated())];
    }

    public function placeValuesDestroy(
        StorageLocation $storageLocation,
        StoragePlaceLevel $placeLevel,
        StoragePlaceValue $placeValue
    ): Response {
        $this->locations->deletePlaceValue($placeValue);

        return response()->noContent();
    }
}
