<?php

namespace App\Shared\OpenApi;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Marks every operation behind auth:sanctum as requiring the bearer token
 * (native) or the session cookie (web), so the contract and the generated
 * client know which endpoints need a signed-in user.
 */
final class MarksAuthenticatedOperations implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $middleware = $routeInfo->route->gatherMiddleware();

        $authenticated = collect($middleware)->contains(
            fn (mixed $m) => is_string($m) && ($m === 'auth:sanctum' || str_starts_with($m, 'auth:sanctum,')),
        );

        if ($authenticated) {
            $operation->addSecurity(new SecurityRequirement(['bearer' => []]));
            $operation->addSecurity(new SecurityRequirement(['session' => []]));
        }
    }
}
