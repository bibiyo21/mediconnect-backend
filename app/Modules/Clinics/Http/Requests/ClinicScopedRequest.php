<?php

namespace App\Modules\Clinics\Http\Requests;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A request on one clinic: the clinic-scoped permission is checked before
 * validation, so callers without access learn nothing from validation errors.
 * An unknown clinic passes here and gets 404 from the controller.
 *
 * The check matches what the request touches (ClinicAccess): a target location
 * means access at that location; otherwise clinicWide() decides between the
 * clinic-wide check and the plain member gate.
 */
abstract class ClinicScopedRequest extends FormRequest
{
    abstract protected function permission(): string;

    /** The location this request acts on, when it acts on one (route or body); null otherwise. */
    protected function locationId(): ?string
    {
        return null;
    }

    /** Whether, with no target location, the action is on the clinic itself or a clinic-wide record. */
    protected function clinicWide(): bool
    {
        return false;
    }

    public function authorize(): bool
    {
        $clinicId = (string) $this->route('clinicId');
        if (Clinic::whereKey($clinicId)->doesntExist()) {
            return true;
        }
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }

        $access = app(ClinicAccess::class);
        $locationId = $this->locationId();

        // A location of another clinic passes here and gets 404 from the controller (which checks
        // it belongs to this clinic before authorizing at it), the same as an unknown clinic.
        if ($locationId !== null && Str::isUuid($locationId) && ClinicLocation::whereKey($locationId)->where('clinic_id', $clinicId)->doesntExist()) {
            return true;
        }

        return match (true) {
            $locationId !== null => $access->allowsAtLocation($user, $this->permission(), $locationId),
            $this->clinicWide() => $access->allowsClinicWide($user, $this->permission(), $clinicId),
            default => $access->allows($user, $this->permission(), $clinicId),
        };
    }
}
