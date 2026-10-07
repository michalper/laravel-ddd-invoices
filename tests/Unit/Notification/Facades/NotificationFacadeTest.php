<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Facades;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Str;
use Modules\Notifications\Api\Data\NotifyData;
use Modules\Notifications\Application\Facades\NotificationFacade;
use Modules\Notifications\Infrastructure\Drivers\DriverInterface;
use PHPUnit\Framework\TestCase;

final class NotificationFacadeTest extends TestCase
{
    use WithFaker;

    private DriverInterface $driver;

    private NotificationFacade $notificationFacade;

    protected function setUp(): void
    {
        $this->setUpFaker();

        $this->driver = $this->createMock(DriverInterface::class);
        $this->notificationFacade = new NotificationFacade(
            driver: $this->driver,
        );
    }

    public function test_delivered(): void
    {
        $data = new NotifyData(
            resourceId: Str::uuid(),
            toEmail: $this->faker->email(),
            subject: $this->faker->sentence(),
            message: $this->faker->sentence(),
        );

        $this->driver->expects($this->once())->method('send');

        $this->notificationFacade->notify($data);
    }
}
