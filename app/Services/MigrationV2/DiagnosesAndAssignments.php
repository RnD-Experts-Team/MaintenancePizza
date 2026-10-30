<?php

namespace App\Services\MigrationV2;

use Illuminate\Support\Facades\DB;

/**
 * Diagnoses become inspections; bookings get a technician.
 *
 * A diagnosis was "what we found". In v2 that is an inspection's findings:
 * one done inspection per issue the diagnosis covered, by the issue's
 * technician when it had exactly one.
 *
 * An old booking (assignment) had no technician of its own: technicians hung
 * off the issues. A v2 booking is one technician's: a booking whose issues
 * had one technician gets that technician; one with several is copied, one
 * per technician, so each still sees it on their schedule.
 */
final class DiagnosesAndAssignments extends Converter
{
    public function run(): void
    {
        $this->diagnoses();
        $this->assignments();
    }

    private function diagnoses(): void
    {
        DB::table('diagnoses')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $diagnosis) {
                if (LegacyMap::has('diagnoses', (int) $diagnosis->id, 'issue_inspections')
                    || LegacyMap::has('diagnoses', (int) $diagnosis->id, LegacyMap::SKIPPED)) {
                    continue;
                }

                DB::transaction(function () use ($diagnosis) {
                    $issueIds = DB::table('diagnosis_ticket_issue')->where('diagnosis_id', $diagnosis->id)->pluck('ticket_issue_id');
                    $inspectionIds = [];

                    foreach ($issueIds as $issueId) {
                        $id = (int) DB::table('issue_inspections')->insertGetId([
                            'ticket_issue_id' => $issueId,
                            'technician_id' => $this->soleTechnician([(int) $issueId]),
                            'status' => 'done',
                            'scheduled_date' => substr((string) $diagnosis->created_at, 0, 10),
                            'done_at' => $diagnosis->created_at,
                            'findings' => $diagnosis->body,
                            'mistaken' => $diagnosis->mistaken,
                            'created_by' => $diagnosis->created_by,
                            'created_at' => $diagnosis->created_at,
                            'updated_at' => $diagnosis->updated_at,
                        ]);
                        LegacyMap::put('diagnoses', (int) $diagnosis->id, 'issue_inspections', $id);
                        $inspectionIds[] = $id;
                    }

                    // Every inspection keeps the diagnosis's notes and files: the
                    // later ones get copies, then the originals move to the first.
                    foreach (array_slice($inspectionIds, 1) as $id) {
                        $this->moveMorphs('App\\Models\\Diagnosis', (int) $diagnosis->id, 'App\\Models\\IssueInspection', $id, copy: true);
                    }
                    if ($inspectionIds !== []) {
                        $this->moveMorphs('App\\Models\\Diagnosis', (int) $diagnosis->id, 'App\\Models\\IssueInspection', $inspectionIds[0]);
                    }

                    if ($issueIds->isEmpty()) {
                        // Remembered, so a second run does not report it again.
                        LegacyMap::put('diagnoses', (int) $diagnosis->id, LegacyMap::SKIPPED, 0);
                        LegacyMap::report('inspections', "Diagnosis #{$diagnosis->id} covered no issue and was not converted", ['diagnosis_id' => $diagnosis->id], 'warning');
                    }
                });
            }
        });
    }

    private function assignments(): void
    {
        DB::table('assignments')->whereNull('technician_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $assignment) {
                if (LegacyMap::has('assignments', (int) $assignment->id, 'assignments')) {
                    continue;
                }

                DB::transaction(function () use ($assignment) {
                    $issueIds = DB::table('assignment_ticket_issue')->where('assignment_id', $assignment->id)->pluck('ticket_issue_id')->map(fn ($id) => (int) $id)->all();
                    $technicians = DB::table('technician_ticket_issue')->whereIn('ticket_issue_id', $issueIds)->distinct()->orderBy('technician_id')->pluck('technician_id')->map(fn ($id) => (int) $id)->all();

                    LegacyMap::put('assignments', (int) $assignment->id, 'assignments', (int) $assignment->id);

                    if ($technicians === []) {
                        LegacyMap::report('bookings', "Booking #{$assignment->id} had no technician on its issues; left without one", ['assignment_id' => $assignment->id], 'warning');

                        return;
                    }

                    DB::table('assignments')->where('id', $assignment->id)->update(['technician_id' => $technicians[0]]);

                    foreach (array_slice($technicians, 1) as $technicianId) {
                        $copy = (array) $assignment;
                        unset($copy['id']);
                        $copy['technician_id'] = $technicianId;
                        $copyId = (int) DB::table('assignments')->insertGetId($copy);
                        LegacyMap::put('assignments', (int) $assignment->id, 'assignments', $copyId);

                        foreach ($issueIds as $issueId) {
                            DB::table('assignment_ticket_issue')->insert([
                                'assignment_id' => $copyId,
                                'ticket_issue_id' => $issueId,
                                'created_at' => $assignment->created_at,
                                'updated_at' => $assignment->updated_at,
                            ]);
                        }

                        // The copy is the same booking: it keeps the history of
                        // its moves and its notes and files.
                        foreach (DB::table('assignment_delays')->where('assignment_id', $assignment->id)->orderBy('id')->get() as $delay) {
                            $row = (array) $delay;
                            unset($row['id']);
                            $row['assignment_id'] = $copyId;
                            DB::table('assignment_delays')->insert($row);
                        }
                        $this->moveMorphs('App\\Models\\Assignment', (int) $assignment->id, 'App\\Models\\Assignment', $copyId, copy: true);
                    }

                    if (count($technicians) > 1) {
                        LegacyMap::report('bookings', "Booking #{$assignment->id} covered ".count($technicians).' technicians and was copied once per technician', ['assignment_id' => $assignment->id]);
                    }
                });
            }
        });
    }

    /** @param  list<int>  $issueIds */
    private function soleTechnician(array $issueIds): ?int
    {
        $ids = DB::table('technician_ticket_issue')->whereIn('ticket_issue_id', $issueIds)->distinct()->pluck('technician_id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
