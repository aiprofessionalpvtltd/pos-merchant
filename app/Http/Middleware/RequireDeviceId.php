<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;

class RequireDeviceId
{
    public function handle(Request $request, Closure $next)
    {
        $deviceId = $request->header('X-EXELO-Device-Id');

        if (! is_string($deviceId) || $deviceId === '' || strlen($deviceId) > 100) {
            throw new ApiException('request.device_id_missing', 'X-EXELO-Device-Id header is required', 400);
        }

        return $next($request);
    }
}
