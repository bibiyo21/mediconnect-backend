<?php

namespace App\Shared\Events;

/**
 * A fact that happened in a module, published through the transactional
 * outbox. The payload holds identifiers and non-sensitive facts only;
 * listeners reload current state from PostgreSQL.
 *
 * Delivery is at least once: listeners must be idempotent.
 */
interface DomainEvent
{
    /** Stable name stored in outbox_events.event_type, e.g. "users.account_registered". */
    public static function eventType(): string;

    public function aggregateType(): string;

    public function aggregateId(): string;

    /** @return array<string, scalar|null> */
    public function payload(): array;

    /** @param array<string, scalar|null> $payload */
    public static function fromOutbox(string $aggregateId, array $payload): static;
}
