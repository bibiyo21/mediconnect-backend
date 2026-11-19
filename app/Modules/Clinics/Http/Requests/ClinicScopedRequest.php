<?php

namespace App\Modules\Clinics\Http\Requests;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request on one clinic: the clinic-scoped permission is checked before
 * validation, so callers without access learn nothing from validation errors.
 * An unknown clinic passes here and gets 404 from the controller.
 */
abstract class ClinicScopedRequest extends FormRequest
{
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        $clinicId = (string) $this->route('clinicId');
        if (Clinic::whereKey($clinicId)->doesntExist()) {
            return true;
        }
        $user = $this->user();

        return $user instanceof User && app(ClinicAccess::class)->allows($user, $this->permission(), $clinicId);
    }
}
