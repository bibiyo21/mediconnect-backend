<?php

namespace App\Modules\Notifications\Contracts;

/**
 * Cuts a user's live WebSocket connections when the authorization that allowed them ends
 * (increment 5.4b, M-3: a subscription must not outlive its authorization). Other modules call
 * this contract and never the Notifications internals.
 *
 * Call it from the code path that ends the authorization: logout or token revocation, a revoked
 * device, a suspended or deactivated account, a removed clinic membership, and any change that
 * narrows a membership's role or location. It is safe to call inside a database transaction: the
 * termination is queued only after the outermost transaction commits and never on a rollback.
 * It never throws, so it can never break the request that ended the authorization.
 *
 * A terminated client reconnects and asks for its channels again, so it keeps exactly the
 * channels it is still authorized for. Real-time payloads are ids, counts and queue numbers
 * (see RealtimeEvent), so a stale subscription in the short window before termination is harmless.
 */
interface TerminatesRealtimeSessions
{
    /**
     * @param  string  $reason  short machine label for the log (for example "logout"); never personal data
     */
    public function terminateForUser(string $userId, string $reason): void;
}
