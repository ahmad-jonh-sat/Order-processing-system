<?php

namespace App\Support;

use RuntimeException;

class JwtVerifier
{
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_array($payload)) {
            return null;
        }

        $publicKey = $this->readKey((string) config('jwt.public_key_path'));
        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $this->base64UrlDecode($encodedSignature),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1 || ($payload['exp'] ?? 0) < time() || ($payload['nbf'] ?? 0) > time()) {
            return null;
        }

        if (($payload['iss'] ?? null) !== config('jwt.issuer') || ($payload['aud'] ?? null) !== config('jwt.audience')) {
            return null;
        }

        return $payload;
    }

    private function readKey(string $path): string
    {
        $key = @file_get_contents($path);

        if ($key === false) {
            throw new RuntimeException("JWT public key is not readable at [{$path}].");
        }

        return $key;
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
