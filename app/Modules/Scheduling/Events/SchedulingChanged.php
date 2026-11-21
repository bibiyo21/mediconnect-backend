<?php

namespace App\Modules\Scheduling\Events;

use App\Shared\Events\DomainEvent;

/**
 * Something that feeds a doctor's availability changed (service mode,
 * schedule, exception, policy). Carries only the doctor id: the availability
 * refresh (increment 4.6) reloads current state from PostgreSQL.
 */
final class SchedulingChanged implements DomainEvent
{
    public function __construct(public readonly string $id) {}

    public static function eventType(): string
    {
        return 'scheduling.inputs_changed';
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
