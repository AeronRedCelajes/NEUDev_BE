<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SingleSessionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Log token values for debugging
        if ($user) {
            \Log::info('SingleSessionMiddleware:', [
                'stored_token' => $user->lastToken,
                'request_token' => $request->bearerToken()
            ]);
        }

        // If the user is authenticated and the current token doesn't match the stored token:
        if ($user && $user->lastToken !== $request->bearerToken()) {
            // Revoke all tokens for the user to force logout
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Your account was logged in from another location.'
            ], 401);
        }

        return $next($request);
    }
}