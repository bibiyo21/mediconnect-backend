<?php

/*
 * Domain events that may be written to the transactional outbox, by stable
 * event type. An event class must be registered here before it can be
 * recorded, so a typo cannot create events nobody can read back.
 */
return [
    'events' => [
        // 'users.account_registered' => \App\Modules\Users\Events\AccountRegistered::class,
    ],
];
