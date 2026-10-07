<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Domain;

use Modules\Invoices\Domain\Enums\StatusEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusEnumTest extends TestCase
{
    /**
     * The full 3x3 matrix, so the state machine is documented as executable
     * specification rather than prose someone has to trust.
     */
    #[DataProvider('transitions')]
    public function test_transition_matrix(StatusEnum $from, StatusEnum $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }

    /** @return iterable<string, array{StatusEnum, StatusEnum, bool}> */
    public static function transitions(): iterable
    {
        yield 'draft to draft' => [StatusEnum::Draft, StatusEnum::Draft, false];
        yield 'draft to sending' => [StatusEnum::Draft, StatusEnum::Sending, true];
        yield 'draft to sent-to-client' => [StatusEnum::Draft, StatusEnum::SentToClient, false];

        yield 'sending to draft' => [StatusEnum::Sending, StatusEnum::Draft, false];
        yield 'sending to sending' => [StatusEnum::Sending, StatusEnum::Sending, false];
        yield 'sending to sent-to-client' => [StatusEnum::Sending, StatusEnum::SentToClient, true];

        yield 'sent-to-client to draft' => [StatusEnum::SentToClient, StatusEnum::Draft, false];
        yield 'sent-to-client to sending' => [StatusEnum::SentToClient, StatusEnum::Sending, false];
        yield 'sent-to-client to sent-to-client' => [StatusEnum::SentToClient, StatusEnum::SentToClient, false];
    }

    public function test_sent_to_client_is_terminal(): void
    {
        self::assertSame([], StatusEnum::SentToClient->allowedTransitions());
    }

    public function test_every_case_is_covered_by_the_transition_map(): void
    {
        foreach (StatusEnum::cases() as $case) {
            self::assertIsList($case->allowedTransitions());
        }
    }
}
