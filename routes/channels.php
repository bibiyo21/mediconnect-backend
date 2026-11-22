<?php

use App\Modules\Notifications\Realtime\ChannelAuthorizer;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Channel authorization (rbac-permissions.md §8, architecture.md §15.2).
 *
 * Names are given without the `presence-` prefix that clients add. Every channel is a presence
 * channel so that Reverb records the user id on the connection and can terminate it when the
 * authorization ends (increment 5.4b, M-3; see Channels). BroadcastAuthRequest refuses the
 * `private-` spelling of these names, so a subscriber always carries a user id.
 *
 * The callback answer becomes the presence `channel_data`. Laravel treats a falsy answer as a
 * refusal and an empty array is falsy, so an allowed subscription answers an empty OBJECT:
 * `user_info` is `{}` (no name, email, role or permission) and `user_id` is the user's UUID only.
 *
 * The public doctor-availability channel (D-31) is not owner-approved and conflicts with
 * CLAUDE.md §19. The walk-in entry and consultation channels are added with their modules
 * (increment 5.5 and Phase 8).
 */

/** Allowed: an empty user_info object. Refused: false. */
$member = fn (bool $allowed): object|false => $allowed ? (object) [] : false;

Broadcast::channel('user.{userId}', fn (User $user, string $userId) => $member(app(ChannelAuthorizer::class)->user($user, $userId)));

Broadcast::channel('doctor.{doctorProfileId}', fn (User $user, string $doctorProfileId) => $member(app(ChannelAuthorizer::class)->doctor($user, $doctorProfileId)));

Broadcast::channel('clinic.{locationId}', fn (User $user, string $locationId) => $member(app(ChannelAuthorizer::class)->clinic($user, $locationId)));

Broadcast::channel('clinic.{locationId}.queue', fn (User $user, string $locationId) => $member(app(ChannelAuthorizer::class)->clinicQueue($user, $locationId)));
