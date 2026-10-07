<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Presentation;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Modules\Invoices\Presentation\Http\Requests\Rules\StrictInteger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule exists because Laravel's `integer` rule is coercive and the command
 * layer is not, which once turned "2" into a TypeError and a 500.
 *
 * The float case lives here and not in the HTTP dataset for a reason worth
 * recording: it is unexpressible through postJson(). json_encode(2.0) emits "2",
 * so by the time the application sees the request the value is an int — only a
 * real client sending the raw literal 2.0 can deliver a float, and this test is
 * the stand-in for that client.
 *
 * Exercised through a hand-built validation factory rather than by invoking the
 * rule object directly: the $fail callback's signature is framework-internal, and
 * the factory needs no container — so this stays a true unit test while testing
 * the rule exactly the way the validator will run it.
 */
final class StrictIntegerTest extends TestCase
{
    #[DataProvider('rejected')]
    public function test_anything_that_is_not_a_php_integer_is_rejected(mixed $value): void
    {
        $validator = $this->factory()->make(['quantity' => $value], ['quantity' => [new StrictInteger]]);

        self::assertTrue($validator->fails());
        self::assertArrayHasKey('quantity', $validator->errors()->toArray());
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejected(): iterable
    {
        yield 'coercible string' => ['2'];
        yield 'non-numeric string' => ['two'];
        yield 'float, even an integral one' => [2.0];
        yield 'bool' => [true];
        yield 'null' => [null];
    }

    public function test_a_genuine_integer_passes(): void
    {
        $validator = $this->factory()->make(['quantity' => 2], ['quantity' => [new StrictInteger]]);

        self::assertFalse($validator->fails());
    }

    private function factory(): Factory
    {
        return new Factory(new Translator(new ArrayLoader, 'en'));
    }
}
