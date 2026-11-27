<?php

namespace App\Modules\Authentication\Contracts;

/**
 * Whether a user still holds a credential that could open a connection (increment 5.6-0, realtime
 * reconciliation). Other modules ask this contract and never read the authentication tables.
 */
interface LiveSessions
{
    /**
     * Of the given user ids, those that are ACTIVE accounts holding at least one live credential:
     * a full-ability unexpired access token or an unused unexpired refresh token on a non-revoked
     * device, or a non-revoked WEB (browser) device seen within the session lifetime plus a small
     * margin (web sessions live in Redis and cannot be listed per user, so the device heartbeat
     * stands for them; see LiveSessionChecker).
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function liveUserIds(array $userIds): array;
}
