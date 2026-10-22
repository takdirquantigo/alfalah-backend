<?php

namespace App\Http\Middleware;

use App\Models\Customers;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticateCustomerWithToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'Token not provided'], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken || ! $accessToken->tokenable) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        if (! $accessToken->tokenable instanceof Customers) {
            return response()->json(['error' => 'Invalid token for customer access'], 403);
        }

        $customer = $accessToken->tokenable;
        $request->setUserResolver(fn () => $customer);
        $request->merge(['auth_customer' => $customer]);

        return $next($request);
    }
}
