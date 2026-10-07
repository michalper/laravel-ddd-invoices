<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Requests\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An integer that is actually an integer.
 *
 * Laravel's `integer` rule validates with FILTER_VALIDATE_INT, which happily
 * accepts "2" — it checks that a value could be coerced, not that it is typed.
 * This API is deliberately explicit rather than coercive, and the command layer
 * backs that with int parameters under strict_types, so a coercible string that
 * slipped past validation did not become a 2: it became a TypeError and a 500.
 * Found live, by sending exactly what the docblock claimed was rejected.
 */
final class StrictInteger implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail('The :attribute field must be a JSON integer; a quoted number is a string.');
        }
    }
}
