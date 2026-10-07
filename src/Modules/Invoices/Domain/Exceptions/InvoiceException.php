<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Exceptions;

use RuntimeException;

/**
 * Base class for every failure the Invoices module raises on purpose.
 *
 * Presentation registers a single `renderable()` typed against this class, which
 * works because Laravel matches exception callbacks with `is_a()`. Adding a new
 * failure therefore costs one subclass plus one row in InvoiceExceptionMapper,
 * and the domain stays free of HTTP concerns.
 */
abstract class InvoiceException extends RuntimeException {}
