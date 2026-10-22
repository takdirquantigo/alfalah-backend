<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticateWithToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken || !$accessToken->tokenable) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        if (! $accessToken->tokenable instanceof User) {
            return response()->json(['error' => 'Invalid token for admin access'], 403);
        }

        $user = $accessToken->tokenable;
        $request->setUserResolver(fn () => $user);
        $request->merge(['auth_user' => $user]);

        return $next($request);
    }
}
