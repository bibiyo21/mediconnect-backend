<?php

namespace App\Modules\Presence\Http\Requests;

/** GET /presence/check-out/preview. Same coarse gate and ids as the check-out itself; advisory, so it changes nothing. */
final class CheckOutPreviewRequest extends PresenceActionRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('presence.check_out') || $user->can('presence.manage'));
    }
}
