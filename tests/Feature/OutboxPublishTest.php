<?php

namespace Tests\Feature;

use App\Jobs\PublishOutboxEventJob;
use App\Models\Issue;
use App\Models\MaintenanceOutboxEvent;
use App\Models\Store;
use App\Services\MaintenanceEvents\MaintenanceEventFactory;
use App\Services\MaintenanceEvents\MaintenanceOutboxService;
use App\Services\Nats\JetStreamPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The outbox, as in the other services: an envelope from the event factory,
 * a row from the outbox service, a PublishOutboxEventJob for it, and
 * outbox:publish-pending for anything a job did not get out.
 */
class OutboxPublishTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    public function test_opening_a_store_ticket_tells_that_stores_mos(): void
    {
        Queue::fake();
        $this->fakeAuthServer();
        $store = Store::factory()->create(['store_number' => '03795-00001']);
        $oven = Issue::factory()->create(['title' => 'Oven']);

        $ticketId = $this->postJson("/api/stores/{$store->store_number}/tickets", [
            'issues' => [
                ['issue_id' => $oven->id, 'priority' => 'urgent', 'description' => 'Cold'],
                ['other_title' => 'Door', 'priority' => 'low', 'description' => 'Squeaks'],
            ],
        ], $this->headers())->assertCreated()->json('data.id');

        $row = MaintenanceOutboxEvent::query()->sole();
        $this->assertSame('notifications.v1.notification.role.send', $row->subject);
        $this->assertSame('maintenance-system', $row->payload['source']);
        $this->assertSame(['web'], $row->payload['data']['channels']);
        $this->assertSame(['MOS'], $row->payload['data']['roles']);
        $this->assertSame(['03795-00001'], $row->payload['data']['stores']);
        $this->assertSame([
            'type' => 'maintenance_ticket_created',
            'title' => 'New maintenance ticket',
            'body' => "Ticket #{$ticketId} for Store 03795-00001: Oven (Urgent), Door.",
            'action_url' => "/dashboard/maintenance-tickets/{$ticketId}?store=03795-00001",
        ], $row->payload['data']['payload']);

        Queue::assertPushed(PublishOutboxEventJob::class, fn (PublishOutboxEventJob $job) => $job->outboxEventId === (string) $row->id);
    }

    public function test_an_off_system_ticket_has_no_store_to_tell(): void
    {
        Queue::fake();
        $this->fakeAuthServer();

        $this->postJson('/api/tickets', [
            'other_store' => 'Warehouse',
            'issues' => [['other_title' => 'Door', 'priority' => 'low', 'description' => 'Squeaks']],
        ], $this->headers())->assertCreated();

        $this->assertSame(0, MaintenanceOutboxEvent::query()->count());
    }

    public function test_dev_mode_records_the_testing_subjects(): void
    {
        config(['nats.dev_mode' => true]);

        $subject = 'notifications.v1.notification.role.send';
        $row = app(MaintenanceOutboxService::class)->record($subject, app(MaintenanceEventFactory::class)->make($subject, []));

        $this->assertSame('notifications.testing.v1.notification.role.send', $row->subject);
        $this->assertSame('notifications.testing.v1.notification.role.send', $row->payload['type']);
    }

    public function test_publish_pending_sends_what_a_job_did_not(): void
    {
        $row = MaintenanceOutboxEvent::query()->create([
            'subject' => 'notifications.v1.notification.role.send',
            'type' => 'notifications.v1.notification.role.send',
            'payload' => ['data' => []],
        ]);

        $published = [];
        $this->app->instance(JetStreamPublisher::class, new class($published) extends JetStreamPublisher {
            public function __construct(private array &$published)
            {
            }

            public function publish(string $subject, array $payload): array
            {
                $this->published[] = $subject;

                return ['seq' => 1];
            }
        });

        $this->artisan('outbox:publish-pending')->assertSuccessful();

        $this->assertSame(['notifications.v1.notification.role.send'], $published);
        $this->assertNotNull($row->fresh()->published_at);
    }
}
