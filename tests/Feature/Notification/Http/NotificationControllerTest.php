<?php

declare(strict_types=1);

namespace Tests\Feature\Notification\Http;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;
use Tests\TestCase;

final class NotificationControllerTest extends TestCase
{
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFaker();
    }

    public function test_delivered_hook_dispatches_event(): void
    {
        Event::fake([WebhookDeliveredEvent::class]);

        $uri = route('notification.hook', [
            'action' => 'delivered',
            'reference' => $this->faker->uuid(),
        ]);

        $this->getJson($uri)->assertNoContent();

        Event::assertDispatched(WebhookDeliveredEvent::class);
    }

    public function test_unknown_action_returns_not_found(): void
    {
        $uri = route('notification.hook', [
            'action' => 'unknownaction',
            'reference' => $this->faker->uuid(),
        ]);

        $this->getJson($uri)->assertNotFound();
    }

    public function test_invalid_reference_pattern_returns_not_found(): void
    {
        $uri = route('notification.hook', [
            'action' => 'delivered',
            'reference' => 'not-a-uuid',
        ]);

        $this->getJson($uri)->assertNotFound();
    }
}
