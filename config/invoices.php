<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | Notification delivery strategy
    |---------------------------------------------------------------------------
    |
    | "outbox"  — record the intent in the same transaction as the status change
    |             and let a queue worker reach the provider, with retries and
    |             `invoices:reconcile` as the safety net. This is the default
    |             because it has no window in which a send can be lost.
    |
    | "direct"  — call the provider immediately, inside the caller's transaction.
    |             Needs no queue worker, which makes it handy for a quick demo,
    |             but it holds a row lock across an external call.
    |
    */
    'notifier' => env('INVOICE_NOTIFIER', 'outbox'),

    /*
    |---------------------------------------------------------------------------
    | Reconciliation threshold (minutes)
    |---------------------------------------------------------------------------
    |
    | How long a pending outbox message may sit before `invoices:reconcile`
    | treats it as stalled and re-dispatches it.
    |
    */
    'reconcile_after_minutes' => (int) env('INVOICE_RECONCILE_AFTER_MINUTES', 15),
];
