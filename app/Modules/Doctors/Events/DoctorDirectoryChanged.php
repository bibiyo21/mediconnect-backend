<?php

namespace App\Modules\Doctors\Events;

use App\Shared\Events\DomainEvent;

/**
 * Something public about this doctor_profile changed, so its search documents must be
 * rebuilt (architecture.md §12.3). Carries only the id: the indexer reloads
 * current state from PostgreSQL, so delivery order does not matter.
 */
final class DoctorDirectoryChanged implements DomainEvent
{
    public function __construct(public readonly string $id) {}

    public static function eventType(): string
    {
        return 'doctors.directory_changed';
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
