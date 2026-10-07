<?php

declare(strict_types=1);

namespace Tests\Support\Invoices;

use Modules\Invoices\Application\Ports\TransactionManagerInterface;

/**
 * The payoff of having a transaction port at all: the send workflow is unit
 * testable without booting the framework to get a database connection.
 */
final class ImmediateTransactionManager implements TransactionManagerInterface
{
    public int $runs = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $this->runs++;

        return $operation();
    }
}
