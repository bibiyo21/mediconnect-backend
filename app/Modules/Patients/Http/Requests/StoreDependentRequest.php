<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Enums\DependentRelationship;
use App\Shared\Enums\Sex;
use App\Shared\Validation\BirthDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /patients/me/dependents. */
final class StoreDependentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the guardian is the signed-in user; checked in the action
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => BirthDate::anyAge(),
            'sex' => ['nullable', Rule::enum(Sex::class)],
            'relationship' => ['required', Rule::enum(DependentRelationship::class)],
            /** The guardian confirms they are authorised to manage this person's health information. */
            'guardian_attestation' => ['required', 'accepted'],
            /** The attestation version the app displayed; stale apps are refused. */
            'guardian_attestation_version' => ['required', 'string', Rule::in([config('mediconnect.dependents.guardian_attestation_version')])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...BirthDate::anyAgeMessages('date_of_birth'),
            'guardian_attestation.accepted' => 'Confirm that you are authorised to manage this person\'s health information.',
            'guardian_attestation.required' => 'Confirm that you are authorised to manage this person\'s health information.',
        ];
    }

    /** @return array{first_name: string, middle_name: string|null, last_name: string, date_of_birth: string, sex: string|null, relationship: string} */
    public function person(): array
    {
        return [
            'first_name' => trim((string) $this->input('first_name')),
            'middle_name' => $this->filled('middle_name') ? trim((string) $this->input('middle_name')) : null,
            'last_name' => trim((string) $this->input('last_name')),
            'date_of_birth' => (string) $this->input('date_of_birth'),
            'sex' => $this->input('sex'),
            'relationship' => (string) $this->input('relationship'),
        ];
    }
}
