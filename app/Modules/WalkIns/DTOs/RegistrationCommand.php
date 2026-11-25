<?php

namespace App\Modules\WalkIns\DTOs;

use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Enums\PayerType;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Enums\PriorityReason;
use App\Modules\WalkIns\Enums\RegistrationChannel;

/**
 * One registration, after the request is validated and the caller is authorized. The channel comes from who the caller is,
 * never from the request. Exactly one of $profile and $guestName is set (a patient entry or a guest entry).
 */
final class RegistrationCommand
{
    public function __construct(
        public readonly User $user,
        public readonly RegistrationChannel $channel,
        public readonly DoctorService $service,
        public readonly string $idempotencyKey,
        public readonly PayerType $payerType,
        public readonly ?PatientProfile $profile = null,
        public readonly ?string $guestName = null,
        /** The guest's phone normalised to E.164 (PhoneNumber::parse), or null. */
        public readonly ?string $guestPhoneE164 = null,
        public readonly ?string $hmoCardName = null,
        public readonly PriorityLevel $priority = PriorityLevel::Normal,
        public readonly ?PriorityReason $priorityReason = null,
        public readonly ?string $priorityNote = null,
        public readonly ?string $privacyNoticeVersion = null,
        public readonly bool $capacityOverride = false,
        public readonly bool $acknowledgeLateRisk = false,
    ) {}

    public function isRemote(): bool
    {
        return $this->channel === RegistrationChannel::PatientApp;
    }
}
