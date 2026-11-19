<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /doctors/me. Scalar fields change only when sent; the lists
 * (languages, specialties, education, experience) replace the whole set.
 */
final class UpdateMyDoctorProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the caller's own profile (controller)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $year = (int) now()->format('Y');

        return [
            'display_name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'professional_title' => ['sometimes', 'nullable', 'string', 'max:60'],
            'biography' => ['sometimes', 'nullable', 'string', 'max:3000'],
            /** Digits only. Locked while under review and after verification. */
            'prc_license_number' => ['sometimes', 'string', 'regex:/^\d{5,10}$/'],
            'medical_school' => ['sometimes', 'nullable', 'string', 'max:200'],
            'years_experience' => ['sometimes', 'nullable', 'integer', 'between:0,70'],
            'gender' => ['sometimes', 'nullable', 'in:FEMALE,MALE,OTHER'],
            /** ISO 639 codes, for example en, fil, ceb. */
            'languages' => ['sometimes', 'array', 'max:10'],
            'languages.*' => ['string', 'regex:/^[a-z]{2,3}$/', 'distinct'],
            'specialties' => ['sometimes', 'array', 'max:5'],
            'specialties.*' => ['array:specialty_id,subspecialty_id,is_primary,board_certified'],
            'specialties.*.specialty_id' => ['required', 'uuid', Rule::exists('specialties', 'id')->where('is_active', true)],
            'specialties.*.subspecialty_id' => ['nullable', 'uuid', Rule::exists('subspecialties', 'id')->where('is_active', true)],
            'specialties.*.is_primary' => ['sometimes', 'boolean'],
            'specialties.*.board_certified' => ['sometimes', 'boolean'],
            'education' => ['sometimes', 'array', 'max:10'],
            'education.*' => ['array:institution,degree,year_completed'],
            'education.*.institution' => ['required', 'string', 'max:200'],
            'education.*.degree' => ['required', 'string', 'max:160'],
            'education.*.year_completed' => ['nullable', 'integer', 'between:1950,'.$year],
            'experience' => ['sometimes', 'array', 'max:20'],
            'experience.*' => ['array:organization,position,started_on,ended_on'],
            'experience.*.organization' => ['required', 'string', 'max:200'],
            'experience.*.position' => ['required', 'string', 'max:160'],
            'experience.*.started_on' => ['nullable', 'date_format:Y-m-d'],
            'experience.*.ended_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['prc_license_number.regex' => 'Enter the PRC licence number using digits only.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $specialties = (array) $this->input('specialties', []);
            if ($specialties !== [] && count(array_filter($specialties, fn ($s) => (bool) ($s['is_primary'] ?? false))) !== 1) {
                $v->errors()->add('specialties', 'Mark exactly one specialty as your main one.');
            }
            $seen = [];
            foreach ($specialties as $i => $s) {
                $key = $s['specialty_id'].'|'.($s['subspecialty_id'] ?? '');
                if (isset($seen[$key])) {
                    $v->errors()->add("specialties.{$i}.specialty_id", 'This specialty is listed twice.');
                }
                $seen[$key] = true;
                if (! empty($s['subspecialty_id']) && DB::table('subspecialties')->where('id', $s['subspecialty_id'])->value('specialty_id') !== $s['specialty_id']) {
                    $v->errors()->add("specialties.{$i}.subspecialty_id", 'This subspecialty belongs to a different specialty.');
                }
            }
            foreach ((array) $this->input('experience', []) as $i => $e) {
                if (! empty($e['started_on']) && ! empty($e['ended_on']) && $e['ended_on'] < $e['started_on']) {
                    $v->errors()->add("experience.{$i}.ended_on", 'The end date must be after the start date.');
                }
            }
        });
    }
}
