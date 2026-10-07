<?php

declare(strict_types=1);

namespace Tests\Support\Invoices;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * Remembers what was logged, so a test can assert on the entries themselves.
 *
 * A fake rather than a double, for the same reason RecordingDriver is one: nothing
 * here is verified through the doubling API, so a mock would be the wrong tool and
 * PHPUnit 13 says so out loud. Where a test wants to assert that a particular level
 * was or was not used, expectLogger()'s mock states that expectation exactly and
 * remains the better fit.
 *
 * LoggerTrait routes all eight PSR-3 level methods through log(), so this stays one
 * method instead of nine.
 */
final class RecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    /**
     * PSR-3 types $level as mixed, so it is narrowed here rather than cast blindly;
     * every caller in this codebase goes through the trait's level methods, which
     * pass a LogLevel string.
     *
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->entries[] = [
            'level' => is_string($level) ? $level : get_debug_type($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * Entries matching a level and carrying a context key.
     *
     * Deliberately not matched on message text: this project's own stance
     * (infection.json5) is that log LEVELS are part of the observability contract
     * and log STRINGS are not, so a test pinned to the wording would contradict it
     * and break on a reword that changes nothing.
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function where(string $level, string $contextKey): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['level'] === $level && array_key_exists($contextKey, $entry['context']),
        ));
    }
}
