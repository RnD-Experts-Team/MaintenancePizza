<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaintenanceSettingUpdateRequest;
use App\Services\MaintenanceSettingsService;

/**
 * The owner's settings (docs/MAINTENANCE-V2.md §2 "Settings"): approval
 * thresholds and how long an idle trip link lives.
 */
class MaintenanceSettingController extends Controller
{
    public function __construct(private MaintenanceSettingsService $settings)
    {
    }

    public function show(): array
    {
        return ['data' => $this->settings->all()];
    }

    public function update(MaintenanceSettingUpdateRequest $request): array
    {
        return ['data' => $this->settings->update($request->validated(), $request->user())];
    }
}
