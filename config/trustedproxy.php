<?php

/*
 * Reverse proxies / load balancers allowed to set X-Forwarded-* headers
 * (read by Laravel's TrustProxies middleware).
 *
 * Default: none. Then request()->ip() is the direct peer and X-Forwarded-For is
 * ignored, which is right for local development and must NOT stay that way
 * behind a proxy: every client would share the proxy's IP, which opens
 * /ready to the internet and puts all users in one rate-limit bucket.
 *
 * Deployments set TRUSTED_PROXIES to the proxy addresses or CIDRs,
 * comma-separated (e.g. "10.0.0.0/8"). Avoid "*" unless the app is only
 * reachable through the proxy.
 */
return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
