<?php

namespace App\Modules\Presence\Http\Requests;

use App\Modules\Presence\Enums\CheckOutReason;
use App\Modules\WalkIns\Enums\CheckOutDisposition;
use Illuminate\Validation\Rule;

/**
 * POST /presence/check-out. The coarse gate is presence.check_out (the doctor) or presence.manage (staff); PresenceAccess decides the rest.
 * The walk-in disposition is required: the system never picks one for a person (D-D). The ids (doctor_id, clinic_location_id) are the
 * presence module's shared ones (PresenceActionRequest, api-specification §2.7).
 */
final class CheckOutRequest extends PresenceActionRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('presence.check_out') || $user->can('presence.manage'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Why, as a fixed code (END_OF_SHIFT, EMERGENCY, PERSONAL, CLINIC_CLOSED, OTHER). No free text. */
            'reason' => ['required', Rule::enum(CheckOutReason::class)],
            /** What happens to the walk-in entries still waiting: HOLD_FOR_REASSIGNMENT keeps them, NOTIFY_AND_CANCEL cancels them with notice. */
            'walkin_disposition' => ['required', Rule::enum(CheckOutDisposition::class)],
            /** Required (true) when NOTIFY_AND_CANCEL cancels a guest entry: the guest was told in person, since a guest has no notification. */
            'guests_informed_in_person' => ['sometimes', 'boolean'],
        ];
    }

    public function reason(): CheckOutReason
    {
        return CheckOutReason::from((string) $this->validated('reason'));
    }

    public function disposition(): CheckOutDisposition
    {
        return CheckOutDisposition::from((string) $this->validated('walkin_disposition'));
    }

    public function guestsInformed(): bool
    {
        return (bool) $this->validated('guests_informed_in_person', false);
    }
}
