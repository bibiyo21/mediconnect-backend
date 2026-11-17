<?php

/*
 * Module boundary rules (architecture.md §6.3).
 *
 * 1. A module may use another module only through its public surface:
 *    Contracts, Events, Enums, DTOs — and its Models for read-only
 *    relationships (architecture.md §6.3 rule 2). Actions, Services, Http,
 *    Jobs, Listeners, Policies and Database of another module are off limits.
 *    Writing through another module's model is a code-review rule: a static
 *    test cannot tell a read from a write.
 * 2. The shared kernel (App\Shared) never depends on a module.
 * 3. No debugging helpers in application code.
 */

$modulesPath = dirname(__DIR__, 2).'/app/Modules';
$modules = array_map('basename', glob($modulesPath.'/*', GLOB_ONLYDIR) ?: []);

$internalLayers = ['Actions', 'Services', 'Http', 'Jobs', 'Listeners', 'Policies', 'Database'];

foreach ($modules as $module) {
    $forbidden = [];

    foreach ($modules as $other) {
        if ($other === $module) {
            continue;
        }

        foreach ($internalLayers as $layer) {
            $forbidden[] = "App\\Modules\\{$other}\\{$layer}";
        }
    }

    arch("{$module} uses other modules only through their public surface")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($forbidden);
}

arch('shared kernel does not depend on modules')
    ->expect('App\\Shared')
    ->not->toUse('App\\Modules');

arch('no debugging helpers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
