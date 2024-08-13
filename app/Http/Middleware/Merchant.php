<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class Merchant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if the authenticated user has the "Merchant" role
        if (Auth::check() && Auth::user()->hasRole('Merchant')) {
            return $next($request);
        }

        // If the user does not have the "Merchant" role, you can return an error or redirect
        return response()->json([
            'message' => 'Access denied. You do not have the required Merchant role to perform this action.'
        ], 403);    }
}
