<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStorageLocationRequest;
use App\Models\StorageLocation;
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
}
