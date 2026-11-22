<?php

namespace App\Modules\Scheduling\Events;

use App\Shared\Events\DomainEvent;

/**
 * A doctor's availability projection was rebuilt (§19 AvailabilityUpdated).
 * Carries only the doctor id; search re-reads the projection (increment 4.8).
 * Holds do not raise it: they change availability for minutes at a time, and
 * search is advisory (a hold revalidates).
 */
final class AvailabilityUpdated implements DomainEvent
{
    public function __construct(public readonly string $id) {}

    public static function eventType(): string
    {
        return 'scheduling.availability_updated';
    }

    public function aggregateType(): string
    {
        return 'doctor_profile';
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function payload(): array
    {
        return [];
    }

    public static function fromOutbox(string $aggregateId, array $payload): static
    {
        return new self($aggregateId);
    }
}
