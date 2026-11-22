<?php

namespace App\Modules\Presence\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for presence writes. Fine-grained authorization (own presence, or presence.manage at the
 * location) is decided by PresenceAccess in the controller; this is only the coarse gate.
 */
class PresenceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('presence.check_in') || $user->can('presence.manage'));
    }

    /** Ids are compared as text further in: accept an upper-case UUID by lower-casing it first. */
    protected function prepareForValidation(): void
    {
        $lowered = [];
        foreach ($this->idFields() as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $lowered[$key] = strtolower(trim($value));
            }
        }
        $this->merge($lowered);
    }

    /** @return list<string> the request fields that hold UUIDs */
    protected function idFields(): array
    {
        return ['doctor_id', 'clinic_location_id'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Clinic staff only: the doctor to act for. Omit to change your own presence. */
            'doctor_id' => ['sometimes', 'nullable', 'uuid'],
            /** Required when acting for another doctor: the location where you hold presence rights. */
            'clinic_location_id' => ['nullable', 'uuid', 'required_with:doctor_id'],
        ];
    }

    public function doctorId(): ?string
    {
        $value = $this->validated('doctor_id');

        return is_string($value) ? $value : null;
    }

    public function locationId(): ?string
    {
        $value = $this->validated('clinic_location_id');

        return is_string($value) ? $value : null;
    }
}
