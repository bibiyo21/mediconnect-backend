<?php

namespace App\Modules\Authentication\Http\Resources;

use App\Modules\Users\Models\User;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user's own account summary. Contact details are the user's
 * own, so they are shown; the mobile number is still masked by default.
 *
 * @mixin User
 */
class AccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $profile = $user->profile;

        return [
            'id' => $user->id,
            'email' => $user->email,
            'mobile_masked' => $user->mobile_e164 ? PhoneNumber::parse($user->mobile_e164)->masked() : null,
            'email_verified' => $user->email_verified_at !== null,
            'mobile_verified' => $user->mobile_verified_at !== null,
            'status' => $user->status->value,
            'locale' => $user->locale,
            /** @var list<string> */
            'roles' => $user->getRoleNames()->values()->all(),
            'profile' => $profile ? [
                'first_name' => $profile->first_name,
                'middle_name' => $profile->middle_name,
                'last_name' => $profile->last_name,
            ] : null,
        ];
    }
}
