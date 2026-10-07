<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Entities;

use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidStatusTransitionException;
use Modules\Invoices\Domain\Exceptions\InvoiceWithoutProductLinesException;
use Modules\Invoices\Domain\ValueObjects\ProductLineCollection;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * The aggregate root — deliberately framework-free.
 *
 * An Eloquent model cannot express what this problem is actually about: invariants
 * that hold no matter which entry point you arrive from. With a model,
 * `$invoice->update(['status' => 'sent-to-client'])` is always one line away. Here
 * there is no public setter at all: the only route to a new status is a named
 * behaviour that consults the state machine first.
 *
 * The id is a UuidInterface throughout, which is also the type NotifyData and
 * WebhookDeliveredEvent speak — so the module seam needs zero string<->uuid
 * conversions.
 */
final class Invoice
{
    private function __construct(
        private readonly UuidInterface $id,
        private readonly string $customerName,
        private readonly string $customerEmail,
        private StatusEnum $status,
        private readonly ProductLineCollection $productLines,
    ) {}

    /**
     * The only public way to create an invoice, and it hardcodes Draft. That turns
     * "an invoice can only be created in draft status" from a rule you validate
     * into a state you cannot represent.
     */
    public static function draft(
        string $customerName,
        string $customerEmail,
        ProductLineCollection $productLines,
        ?UuidInterface $id = null,
    ): self {
        return new self(
            id: $id ?? Uuid::uuid4(),
            customerName: $customerName,
            customerEmail: $customerEmail,
            status: StatusEnum::Draft,
            productLines: $productLines,
        );
    }

    /**
     * Rehydration from persistence. It bypasses draft()'s status rule by design and
     * is named so a reader can see it belongs to the mapper, not to callers.
     */
    public static function restore(
        UuidInterface $id,
        string $customerName,
        string $customerEmail,
        StatusEnum $status,
        ProductLineCollection $productLines,
    ): self {
        return new self(
            id: $id,
            customerName: $customerName,
            customerEmail: $customerEmail,
            status: $status,
            productLines: $productLines,
        );
    }

    /**
     * @throws InvoiceWithoutProductLinesException
     * @throws InvalidStatusTransitionException
     */
    public function markAsSending(): void
    {
        // Because ProductLine enforces positive quantity and unit price in its own
        // constructor, "lines with positive values" reduces to "lines exist".
        if ($this->productLines->isEmpty()) {
            throw InvoiceWithoutProductLinesException::cannotBeSent($this->id);
        }

        $this->transitionTo(StatusEnum::Sending);
    }

    /** @throws InvalidStatusTransitionException */
    public function markAsSentToClient(): void
    {
        $this->transitionTo(StatusEnum::SentToClient);
    }

    public function id(): UuidInterface
    {
        return $this->id;
    }

    public function customerName(): string
    {
        return $this->customerName;
    }

    public function customerEmail(): string
    {
        return $this->customerEmail;
    }

    public function status(): StatusEnum
    {
        return $this->status;
    }

    public function productLines(): ProductLineCollection
    {
        return $this->productLines;
    }

    public function totalPrice(): int
    {
        return $this->productLines->total();
    }

    /**
     * The single enforcement point. Both entry points — the HTTP send endpoint and
     * the delivery webhook listener — reach a status change only through a named
     * behaviour above, so they share this guard and there is no third path.
     *
     * @throws InvalidStatusTransitionException
     */
    private function transitionTo(StatusEnum $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw InvalidStatusTransitionException::between($this->status, $target);
        }

        $this->status = $target;
    }
}
