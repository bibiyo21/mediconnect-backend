<?php

namespace App\Modules\Authentication\Contracts;

use App\Modules\Users\Models\User;

/** Ends sign-ins on behalf of other modules (for example when an account is suspended). */
interface RevokesSessions
{
    /** Every device, refresh token and access token of the user. */
    public function revokeEverything(User $user): void;
}
