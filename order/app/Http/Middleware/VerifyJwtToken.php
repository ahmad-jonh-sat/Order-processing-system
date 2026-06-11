<?php

namespace App\Http\Middleware;

use App\Support\JwtVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyJwtToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $payload = $token ? app(JwtVerifier::class)->verify($token) : null;

        if (! $payload) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('jwt_payload', $payload);

        return $next($request);
    }
}
