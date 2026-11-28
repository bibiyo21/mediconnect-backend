<?php

namespace App\Modules\Clinics\DTOs;

use App\Modules\Clinics\Enums\CapacityMode;

/**
 * A doctor's walk-in settings at one location (doctor_clinic_assignments.walkin_policy, plan Q8 and decisions 97 and 100).
 * Defaults apply to every key that is missing or unreadable, so a bad stored value never opens remote registration:
 * patient-app registration is off until a clinic turns it on (A1). The old allow_pre_checkin_registration key is ignored (B2).
 */
final class WalkInPolicy
{
    public const KEYS = ['allow_patient_app_registration', 'cutoff_minutes_before_close', 'number_prefix', 'capacity_mode', 'recall_limit', 'auto_checkout_grace_minutes'];

    public const DEFAULT_CUTOFF_MINUTES = 30;

    public const DEFAULT_RECALL_LIMIT = 2;

    public const MAX_RECALL_LIMIT = 10;

    /** Minutes after the latest of the clinic's closing, the doctor's last shift end and the doctor's last booked end that day before the automatic check-out (increment 5.8, Q8). */
    public const DEFAULT_AUTO_CHECKOUT_GRACE_MINUTES = 60;

    public const MAX_AUTO_CHECKOUT_GRACE_MINUTES = 720;

    public function __construct(
        public readonly bool $allowPatientAppRegistration = false,
        public readonly int $cutoffMinutesBeforeClose = self::DEFAULT_CUTOFF_MINUTES,
        public readonly ?string $numberPrefix = null,
        public readonly CapacityMode $capacityMode = CapacityMode::Derived,
        public readonly int $recallLimit = self::DEFAULT_RECALL_LIMIT,
        public readonly int $autoCheckoutGraceMinutes = self::DEFAULT_AUTO_CHECKOUT_GRACE_MINUTES,
    ) {}

    /** @param array<string, mixed>|null $stored */
    public static function fromStored(?array $stored): self
    {
        $stored ??= [];
        $cutoff = $stored['cutoff_minutes_before_close'] ?? null;
        $prefix = $stored['number_prefix'] ?? null;
        $recall = $stored['recall_limit'] ?? null;
        $grace = $stored['auto_checkout_grace_minutes'] ?? null;

        return new self(
            ($stored['allow_patient_app_registration'] ?? false) === true,
            is_int($cutoff) && $cutoff >= 0 && $cutoff <= 240 ? $cutoff : self::DEFAULT_CUTOFF_MINUTES,
            is_string($prefix) && preg_match('/^[A-Z]{1,4}$/', $prefix) === 1 ? $prefix : null,
            CapacityMode::tryFrom((string) ($stored['capacity_mode'] ?? '')) ?? CapacityMode::Derived,
            is_int($recall) && $recall >= 1 && $recall <= self::MAX_RECALL_LIMIT ? $recall : self::DEFAULT_RECALL_LIMIT,
            is_int($grace) && $grace >= 0 && $grace <= self::MAX_AUTO_CHECKOUT_GRACE_MINUTES ? $grace : self::DEFAULT_AUTO_CHECKOUT_GRACE_MINUTES,
        );
    }

    /** @return array{allow_patient_app_registration: bool, cutoff_minutes_before_close: int, number_prefix: string|null, capacity_mode: string, recall_limit: int, auto_checkout_grace_minutes: int} */
    public function toStored(): array
    {
        return [
            'allow_patient_app_registration' => $this->allowPatientAppRegistration,
            'cutoff_minutes_before_close' => $this->cutoffMinutesBeforeClose,
            'number_prefix' => $this->numberPrefix,
            'capacity_mode' => $this->capacityMode->value,
            'recall_limit' => $this->recallLimit,
            'auto_checkout_grace_minutes' => $this->autoCheckoutGraceMinutes,
        ];
    }

    /** @param array<string, mixed> $changes keys from KEYS, already validated */
    public function with(array $changes): self
    {
        $stored = [...$this->toStored(), ...$changes];

        return self::fromStored($stored);
    }
}
