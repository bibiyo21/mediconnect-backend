<?php

namespace App\Modules\Doctors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/doctors/{id}/specialties/confirm (D-40) */
final class ConfirmSpecialtiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:doctors.verify` on the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** @var list<string> ids of the doctor's specialty entries, from GET /admin/doctors/{id} */
            'specialty_ids' => ['required', 'array', 'min:1', 'max:20'],
            'specialty_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
