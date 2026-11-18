<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\UserAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The account holder's own patient profile. Shown only to its owner, so the
 * emergency contact number is not masked.
 *
 * @mixin PatientProfile
 */
final class MyPatientProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PatientProfile $profile */
        $profile = $this->resource;
        $user = $profile->user;
        /** @var UserAddress|null $address */
        $address = $user?->addresses->firstWhere('is_primary', true);

        return [
            'id' => $profile->id,
            'first_name' => $profile->first_name,
            'middle_name' => $profile->middle_name,
            'last_name' => $profile->last_name,
            'date_of_birth' => $profile->date_of_birth?->toDateString(),
            'sex' => $profile->sex?->value,
            /** @var 'en'|'fil' */
            'preferred_language' => $user?->profile->preferred_language ?? 'en',
            /** @var array{name: string, phone: string}|null */
            'emergency_contact' => $profile->emergency_contact_name !== null && $profile->emergency_contact_phone !== null
                ? ['name' => $profile->emergency_contact_name, 'phone' => $profile->emergency_contact_phone]
                : null,
            /** @var array{line1: string, line2: string|null, barangay: string|null, city: string, province: string|null, postal_code: string|null, country_code: string}|null */
            'address' => $address ? [
                'line1' => $address->line1,
                'line2' => $address->line2,
                'barangay' => $address->barangay,
                'city' => $address->city,
                'province' => $address->province,
                'postal_code' => $address->postal_code,
                'country_code' => $address->country_code,
            ] : null,
        ];
    }
}
