<?php

namespace App\Modules\Administration\Contracts;

/**
 * Runtime settings stored in system_settings. Never secrets: credentials and
 * keys belong in the environment / secret manager.
 */
interface Settings
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;
}
