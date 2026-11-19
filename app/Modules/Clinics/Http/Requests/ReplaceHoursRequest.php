<?php

namespace App\Modules\Clinics\Http\Requests;

use Illuminate\Validation\Validator;

/** PUT /clinics/{id}/locations/{locationId}/operating-hours: the complete weekly set. */
final class ReplaceHoursRequest extends ClinicScopedRequest
{
    protected function permission(): string
    {
        return 'clinics.manage_hours';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hours' => ['present', 'array', 'max:28'],
            'hours.*' => ['array:day_of_week,opens_at,closes_at'],
            /** 1 = Monday … 7 = Sunday */
            'hours.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i', 'after:hours.*.opens_at'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['hours.*.closes_at.after' => 'Closing time must be after opening time.'];
    }

    /** Split hours on the same day may not overlap. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $byDay = [];
            foreach ((array) $this->input('hours', []) as $i => $h) {
                $byDay[(int) $h['day_of_week']][] = [$i, (string) $h['opens_at'], (string) $h['closes_at']];
            }
            foreach ($byDay as $ranges) {
                usort($ranges, fn ($a, $b) => strcmp($a[1], $b[1]));
                for ($k = 1; $k < count($ranges); $k++) {
                    if ($ranges[$k][1] < $ranges[$k - 1][2]) {
                        $v->errors()->add("hours.{$ranges[$k][0]}.opens_at", 'These hours overlap other hours on the same day.');
                    }
                }
            }
        });
    }
}
