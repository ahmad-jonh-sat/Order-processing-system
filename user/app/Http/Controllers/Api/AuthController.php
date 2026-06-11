<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, JwtService $jwt): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return response()->json($this->tokenResponse($user, $jwt), 201);
    }

    public function login(Request $request, JwtService $jwt): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        return response()->json($this->tokenResponse($user, $jwt));
    }

    public function me(Request $request, JwtService $jwt): JsonResponse
    {
        $token = $request->bearerToken();
        $payload = $token ? $jwt->verify($token) : null;

        if (! $payload) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'user' => $payload['user'] ?? null,
            'claims' => $payload,
        ]);
    }

    private function tokenResponse(User $user, JwtService $jwt): array
    {
        $subject = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];

        return [
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.ttl_seconds'),
            'access_token' => $jwt->issue($subject),
            'user' => $subject,
        ];
    }
}
