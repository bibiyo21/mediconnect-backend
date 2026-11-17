<?php

return [
    /*
     * Networks allowed to call GET /api/v1/ready (load balancers, orchestrators,
     * monitoring). Defaults: loopback and private ranges (RFC 1918, Docker).
     */
    'ready_allowed_cidrs' => array_values(array_filter(explode(',', (string) env(
        'READY_ALLOWED_CIDRS',
        '127.0.0.1/32,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
    )))),
];
