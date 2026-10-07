<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| WHEN a command runs is an application decision; deployment only has to run
| `schedule:run` from cron, or keep `schedule:work` alive (docker-compose does
| the latter). Leaving these commands unscheduled would be dead operability:
| an outbox whose safety net never runs is indistinguishable from no safety
| net, and a retention window nobody enforces is not a retention window.
|
| Only the autonomous commands belong here. invoices:outbox:retry and
| invoices:outbox:abandon are operator tools — scheduling retry --all would
| resurrect every permanently failed message for ever and defeat the whole
| point of the `failed` status.
|
| Both entries take the cross-server lock through the cache (redis by
| default), so running several schedulers is safe; withoutOverlapping guards
| against a slow run meeting its successor.
*/

Schedule::command('invoices:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('invoices:outbox:prune')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
