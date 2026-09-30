<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Gate a route behind one or more roles: ->middleware('role:admin,campmanager')
     *
     * @param  string  ...$roles  Allowed role names.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRoleIn($roles)) {
            abort(403, 'This account does not have permission to perform this action.');
        }

        return $next($request);
    }
}
