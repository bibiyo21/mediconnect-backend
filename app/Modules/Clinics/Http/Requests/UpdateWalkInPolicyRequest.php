<?php

namespace App\Modules\Clinics\Http\Requests;

use App\Modules\Clinics\DTOs\WalkInPolicy;
use App\Modules\Clinics\Enums\CapacityMode;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** PATCH /clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy: only the keys sent change. Unknown keys are refused. */
final class UpdateWalkInPolicyRequest extends ClinicScopedRequest
{
    protected function permission(): string
    {
        return 'clinics.update';
    }

    protected function locationId(): ?string
    {
        $id = (string) $this->route('assignmentId');

        return Str::isUuid($id) ? DoctorClinicAssignment::whereKey($id)->value('clinic_location_id') : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Whether patients may join this doctor's walk-in queue from the app. Off by default; the patient also needs a verified mobile number. */
            'allow_patient_app_registration' => ['sometimes', 'boolean'],
            /** Minutes before closing (or the last walk-in window's end) after which nobody new is admitted. 0 to 240, default 30. */
            'cutoff_minutes_before_close' => ['sometimes', 'integer', 'min:0', 'max:240'],
            /** The queue-number prefix for this doctor's queues at the location, 1 to 4 capital letters; a free letter is used when it is taken or null. */
            'number_prefix' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{1,4}$/'],
            /** DERIVED takes the capacity from the schedule; UNLIMITED admits until the cutoff. */
            'capacity_mode' => ['sometimes', Rule::enum(CapacityMode::class)],
            /** How many times a called patient may be recalled before staff may mark them a no-show. 1 to 10 (WalkInPolicy::MAX_RECALL_LIMIT), default 2. */
            'recall_limit' => ['sometimes', 'integer', 'min:1', 'max:'.WalkInPolicy::MAX_RECALL_LIMIT],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach (array_diff(array_keys($this->all()), WalkInPolicy::KEYS) as $key) {
                $v->errors()->add((string) $key, 'This setting is not supported.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = [];
        if (array_key_exists('allow_patient_app_registration', $validated)) {
            $changes['allow_patient_app_registration'] = $this->boolean('allow_patient_app_registration');
        }
        if (array_key_exists('cutoff_minutes_before_close', $validated)) {
            $changes['cutoff_minutes_before_close'] = $this->integer('cutoff_minutes_before_close');
        }
        if (array_key_exists('number_prefix', $validated)) {
            $changes['number_prefix'] = $validated['number_prefix'];
        }
        if (array_key_exists('capacity_mode', $validated)) {
            $changes['capacity_mode'] = $validated['capacity_mode'];
        }

        if (array_key_exists('recall_limit', $validated)) {
            $changes['recall_limit'] = $this->integer('recall_limit');
        }

        return $changes;
    }
}
