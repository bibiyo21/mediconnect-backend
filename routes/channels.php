<?php

use App\Modules\Notifications\Realtime\ChannelAuthorizer;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Channel authorization (rbac-permissions.md §8, architecture.md §15.2).
 *
 * Names are given without the `private-` prefix that clients add. Only private channels
 * exist here: the public doctor-availability channel (D-31) is not owner-approved and
 * conflicts with CLAUDE.md §19. The walk-in entry and consultation presence channels are
 * added with their modules (increment 5.5 and Phase 8).
 */

Broadcast::channel('user.{userId}', fn (User $user, string $userId) => app(ChannelAuthorizer::class)->user($user, $userId));

Broadcast::channel('doctor.{doctorProfileId}', fn (User $user, string $doctorProfileId) => app(ChannelAuthorizer::class)->doctor($user, $doctorProfileId));

Broadcast::channel('clinic.{locationId}', fn (User $user, string $locationId) => app(ChannelAuthorizer::class)->clinic($user, $locationId));

Broadcast::channel('clinic.{locationId}.queue', fn (User $user, string $locationId) => app(ChannelAuthorizer::class)->clinicQueue($user, $locationId));
