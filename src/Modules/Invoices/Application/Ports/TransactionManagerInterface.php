<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Ports;

/**
 * Exists so the application services stay framework-free and therefore unit
 * testable without the container: a test passes `fn ($operation) => $operation()`
 * instead of booting Laravel just to resolve a database connection.
 */
interface TransactionManagerInterface
{
    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
