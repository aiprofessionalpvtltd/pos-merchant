<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;

/**
 * Route middleware: `pos.permission:employees`. Shop owners hold every key; staff
 * hold the keys their owner gave them.
 */
class RequirePosPermission
{
    public function handle(Request $request, Closure $next, string $key)
    {
        if (! $request->user()?->hasPosPermission($key)) {
            throw new ApiException('auth.permission_denied', 'You do not have access to this', 403, ['required_permission' => $key]);
        }

        return $next($request);
    }
}
