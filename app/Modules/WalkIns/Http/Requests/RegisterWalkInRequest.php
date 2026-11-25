<?php

namespace App\Modules\WalkIns\Http\Requests;

use App\Modules\WalkIns\Enums\PayerType;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Enums\PriorityReason;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /walk-ins. The path is chosen by the body: `guest` is the staff or doctor path (a guest the clinic registers at the desk);
 * `patient_profile_id` is the patient path (yourself or a dependant you are authorized for). Prices, channels, queue numbers, the HMO
 * membership id and the clinic service are never accepted: the server derives them (422 when sent).
 */
final class RegisterWalkInRequest extends FormRequest
{
    /** Fields a client must not send (decision 3 of the 5.5a contract): sending one is a 422, never silently ignored. */
    private const PROHIBITED = [
        'hmo_membership_id', 'clinic_service_id', 'clinic_location_id', 'doctor_id', 'doctor_profile_id',
        'fee', 'fee_amount', 'price', 'amount', 'currency', 'payment', 'payment_method',
        'registration_channel', 'channel', 'status', 'queue_number', 'queue_id', 'local_date', 'triage_priority', 'arrived_at',
        'estimate', 'estimated_call_at_min', 'estimated_call_at_max',
    ];

    /** The coarse gate; who may use which path is decided with the service in hand. */
    public function authorize(): bool
    {
        return $this->user() !== null && ($this->user()->can('walkins.register') || $this->user()->hasRole('DOCTOR'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The in-person doctor service the patient registers for. The server derives the doctor, location and queue from it. */
            'doctor_service_id' => ['required', 'uuid'],
            /** The patient path: your own patient profile, or a dependant you are authorized to manage. Exclusive with `guest`. */
            'patient_profile_id' => ['required_without:guest', 'prohibits:guest', 'uuid'],
            /** The staff or doctor path: a guest the clinic registers at the desk. Exclusive with `patient_profile_id`. */
            'guest' => ['required_without:patient_profile_id', 'array:name,phone'],
            'guest.name' => ['required_with:guest', 'string', 'min:2', 'max:120'],
            /** The guest's contact number, optional (a Philippine mobile number). */
            'guest.phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'payer_type' => ['required', Rule::enum(PayerType::class)],
            /** What the patient says their HMO is called, as printed on the card. Required for HMO, refused for SELF_PAY. Never verified. */
            'hmo_card_name_text' => ['required_if:payer_type,HMO', 'nullable', 'string', 'min:2', 'max:120'],
            /** Staff and doctor only. NORMAL (default), PRIORITY or URGENT. */
            'priority_level' => ['sometimes', Rule::in(['NORMAL', 'PRIORITY', 'URGENT'])],
            /** Required for PRIORITY and URGENT. Staff and doctor only. */
            'priority_reason_code' => ['required_if:priority_level,PRIORITY,URGENT', 'nullable', Rule::enum(PriorityReason::class)],
            /** An optional short note for a priority: no symptoms or diagnosis. At most 140 characters. Staff and doctor only. */
            'priority_note' => ['sometimes', 'nullable', 'string', 'max:140'],
            /** The version of the privacy notice that was shown to the guest: one of the published versions. Required with `guest`. */
            'privacy_notice_version' => ['required_with:guest', 'string', 'max:16', 'regex:/^[A-Za-z0-9._-]+$/', Rule::in((array) config('walkins.privacy_notice.published_versions'))],
            /** Staff and doctor only: add the guest although the queue is over its planned capacity. Audited. */
            'capacity_override' => ['sometimes', 'boolean'],
            /** Patient path: confirm that the clinic may not be able to see you today (the server asks when the range reaches closing time). */
            'acknowledge_late_risk' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach (self::PROHIBITED as $field) {
                if ($this->has($field)) {
                    $v->errors()->add($field, 'This field cannot be sent. The server decides it.');
                }
            }

            $patientPath = $this->filled('patient_profile_id');
            if ($patientPath) {
                foreach (['priority_level', 'priority_reason_code', 'priority_note', 'capacity_override', 'privacy_notice_version'] as $field) {
                    if ($this->has($field)) {
                        $v->errors()->add($field, 'Only the clinic can set this.');
                    }
                }
            }
            if ($this->input('payer_type') !== PayerType::Hmo->value && $this->filled('hmo_card_name_text')) {
                $v->errors()->add('hmo_card_name_text', 'The HMO name applies only when the payer is HMO.');
            }
            if (in_array($this->input('priority_level', 'NORMAL'), ['NORMAL', null], true)) {
                foreach (['priority_reason_code', 'priority_note'] as $field) {
                    if ($this->filled($field)) {
                        $v->errors()->add($field, 'A reason applies only to a priority above normal.');
                    }
                }
            }
            if ($this->filled('guest.phone') && ! $v->errors()->has('guest.phone')) {
                try {
                    PhoneNumber::parse((string) $this->input('guest.phone'));
                } catch (\Throwable) {
                    $v->errors()->add('guest.phone', 'Enter a valid Philippine mobile number.');
                }
            }
        });
    }

    public function isPatientPath(): bool
    {
        return $this->filled('patient_profile_id');
    }

    public function priority(): PriorityLevel
    {
        return PriorityLevel::fromApi((string) $this->input('priority_level', 'NORMAL'));
    }

    public function priorityReason(): ?PriorityReason
    {
        $v = $this->input('priority_reason_code');

        return is_string($v) && $this->priority()->needsReason() ? PriorityReason::from($v) : null;
    }

    public function guestPhoneE164(): ?string
    {
        return $this->filled('guest.phone') ? PhoneNumber::parse((string) $this->input('guest.phone'))->e164 : null;
    }
}
