<?php

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Users\Models\User;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account as administrators see it: identity and status, never medical data.
 * The mobile number stays masked; the email is shown because admins contact users by it.
 *
 * @mixin User
 */
final class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'email' => $user->email,
            'mobile_masked' => $user->mobile_e164 ? PhoneNumber::parse($user->mobile_e164)->masked() : null,
            'status' => $user->status->value,
            /** @var list<string> */
            'roles' => $user->getRoleNames()->values()->all(),
            'first_name' => $user->profile?->first_name,
            'last_name' => $user->profile?->last_name,
            'mfa_enrolled' => (bool) ($user->getAttribute('mfa_enrolled') ?? false),
            'deletion_requested_at' => $user->deletion_requested_at?->toIso8601ZuluString(),
            'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
            'created_at' => $user->created_at?->toIso8601ZuluString(),
        ];
    }
}
