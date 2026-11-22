<?php

namespace App\Modules\Presence\Services;

use App\Modules\Presence\Enums\PresenceChangeMethod;
use App\Modules\Users\Models\User;

/** Who changes presence, how, and from which device (the §8.5 "changed by", "method" and "source device"). */
final class PresenceActor
{
    public function __construct(
        public readonly User $user,
        public readonly PresenceChangeMethod $method,
        public readonly ?string $deviceId,
    ) {}

    /** Whether someone other than the doctor acts. */
    public function isOnBehalf(): bool
    {
        return $this->method !== PresenceChangeMethod::DoctorApp;
    }
}
