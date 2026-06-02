<?php

namespace App\Services\MaintenanceEvents;

use App\Models\MaintenanceOutboxEvent;

class MaintenanceOutboxService
{
    public function record(string $subject, array $payload): MaintenanceOutboxEvent
    {
        $subject = $this->applyEnvironmentPrefix($subject);

        return MaintenanceOutboxEvent::create([
            'subject' => $subject,
            'type' => $subject,
            'payload' => $payload,
        ]);
    }

    private function applyEnvironmentPrefix(string $subject): string
    {
        if (!config('nats.dev_mode')) {
            return $subject;
        }


        if (str_starts_with($subject, 'notifications.v1.')) {
            return str_replace('notifications.v1.', 'notifications.testing.v1.', $subject);
        }

        return $subject;
    }
}