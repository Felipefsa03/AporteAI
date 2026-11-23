<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** OPS: endpoints de operação só existem quando OPS_TOKEN está configurado. */
final class EnsureOpsToken
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('ops.token');
        if (! is_string($expected) || $expected === '') {
            abort(404);
        }

        $provided = $request->header('X-Ops-Token', '');
        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(404);
        }

        return $next($request);
    }
}
