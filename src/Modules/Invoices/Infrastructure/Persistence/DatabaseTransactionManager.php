<?php

declare(strict_types=1);

namespace Modules\Invoices\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Application\Ports\TransactionManagerInterface;

final readonly class DatabaseTransactionManager implements TransactionManagerInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        // Wrapped in a closure that drops the connection argument Laravel passes,
        // so the port stays framework-agnostic.
        $result = $this->connection->transaction(static fn (): mixed => $operation());

        /** @var T $result */
        return $result;
    }
}
