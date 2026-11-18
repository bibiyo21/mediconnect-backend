<?php

namespace App\Modules\Patients\Http\Requests;

use App\Shared\Enums\Sex;
use App\Shared\Exceptions\InvalidValue;
use App\Shared\Validation\BirthDate;
use App\Shared\ValueObjects\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /patients/me: only the fields sent are changed. */
final class UpdateMyPatientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is checked by PatientProfilePolicy in the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $phMobile = function (string $attribute, mixed $value, Closure $fail): void {
            try {
                PhoneNumber::parse((string) $value);
            } catch (InvalidValue) {
                $fail('Enter a valid Philippine mobile number.');
            }
        };

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'date_of_birth' => ['sometimes', ...BirthDate::accountHolder()],
            'sex' => ['sometimes', 'nullable', Rule::enum(Sex::class)],
            'preferred_language' => ['sometimes', 'required', Rule::in(['en', 'fil'])],
            /**
             * Null removes the emergency contact.
             *
             * @var array{name: string, phone: string}|null
             */
            'emergency_contact' => ['sometimes', 'nullable', 'array:name,phone'],
            'emergency_contact.name' => ['required_with:emergency_contact', 'string', 'max:200'],
            'emergency_contact.phone' => ['required_with:emergency_contact', 'string', 'max:20', $phMobile],
            /**
             * Null removes the primary address.
             *
             * @var array{line1: string, line2?: string|null, barangay?: string|null, city: string, province?: string|null, postal_code?: string|null}|null
             */
            'address' => ['sometimes', 'nullable', 'array:line1,line2,barangay,city,province,postal_code'],
            'address.line1' => ['required_with:address', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.barangay' => ['nullable', 'string', 'max:100'],
            'address.city' => ['required_with:address', 'string', 'max:100'],
            'address.province' => ['nullable', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...BirthDate::messages('date_of_birth'),
            'address.postal_code.regex' => 'Enter a 4-digit postal code.',
        ];
    }

    /**
     * The validated changes, trimmed, with the emergency phone in E.164.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $data = $this->validated();

        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) === '' && $field === 'middle_name' ? null : trim($data[$field]);
            }
        }

        if (isset($data['emergency_contact']['phone'])) {
            $data['emergency_contact']['name'] = trim((string) $data['emergency_contact']['name']);
            $data['emergency_contact']['phone'] = PhoneNumber::parse((string) $data['emergency_contact']['phone'])->e164;
        }

        return $data;
    }
}
