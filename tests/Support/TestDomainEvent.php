<?php

namespace Tests\Support;

use App\Shared\Events\DomainEvent;

final class TestDomainEvent implements DomainEvent
{
    public function __construct(public readonly string $id, public readonly string $note) {}

    public static function eventType(): string
    {
        return 'tests.something_happened';
    }

    public function aggregateType(): string
    {
        return 'test';
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function payload(): array
    {
        return ['note' => $this->note];
    }

    public static function fromOutbox(string $aggregateId, array $payload): static
    {
        return new self($aggregateId, (string) $payload['note']);
    }
}
