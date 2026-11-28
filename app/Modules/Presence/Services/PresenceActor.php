<?php

namespace App\Modules\Presence\Services;

use App\Modules\Presence\Enums\PresenceChangeMethod;
use App\Modules\Users\Models\User;
use LogicException;

/**
 * Who changes presence, how, and from which device (the §8.5 "changed by", "method" and "source device"). The SYSTEM actor (auto check-out,
 * increment 5.8) has no user: its rows carry a null changed_by, as the database CHECK requires.
 */
final class PresenceActor
{
    public function __construct(
        public readonly ?User $user,
        public readonly PresenceChangeMethod $method,
        public readonly ?string $deviceId,
    ) {}

    public static function system(): self
    {
        return new self(null, PresenceChangeMethod::System, null);
    }

    /** The acting user. Only a change that has a person behind it may ask for one (a SYSTEM actor has none). */
    public function requireUser(): User
    {
        return $this->user ?? throw new LogicException('A presence change without an acting user must use the SYSTEM actor path.');
    }

    /** Whether someone other than the doctor acts. */
    public function isOnBehalf(): bool
    {
        return $this->method !== PresenceChangeMethod::DoctorApp;
    }
}
