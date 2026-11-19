<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Doctors\Models\DoctorProfile;

/**
 * Profile completeness 0-100 (search ranking input, §11.4). Weights are a
 * starting point and only use information the doctor controls.
 */
final class ProfileCompleteness
{
    public function score(DoctorProfile $p): int
    {
        /** @var list<array{int, bool}> $checks [weight, met] */
        $checks = [
            [10, $p->display_name !== ''],
            [5, $p->professional_title !== null && $p->professional_title !== ''],
            [15, $p->biography !== null && mb_strlen($p->biography) >= 50],
            [20, $p->prc_license_number_hash !== null],
            [20, $p->specialties()->exists()],
            [10, $p->languageCodes() !== []],
            [8, $p->medical_school !== null && $p->medical_school !== ''],
            [7, $p->years_experience !== null],
            [5, $p->education()->exists()],
        ];

        $score = 0;
        foreach ($checks as [$weight, $met]) {
            $score += $met ? $weight : 0;
        }

        return min(100, $score);
    }
}
