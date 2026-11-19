<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\DoctorEducation;
use App\Modules\Doctors\Models\DoctorProfile;
use Illuminate\Http\Request;

/**
 * The public profile page: the directory fields plus biography, school and
 * education. Still no licence number, documents or history.
 *
 * @mixin DoctorProfile
 */
final class PublicDoctorDetailResource extends PublicDoctorResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorProfile $p */
        $p = $this->resource;

        return [
            ...parent::toArray($request),
            'biography' => $p->biography,
            'medical_school' => $p->medical_school,
            'gender' => $p->gender,
            'education' => $p->education->map(fn (DoctorEducation $e) => ['institution' => $e->institution, 'degree' => $e->degree, 'year_completed' => $e->year_completed])->values()->all(),
        ];
    }
}
