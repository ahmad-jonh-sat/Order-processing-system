<?php

namespace App\Support;

use RuntimeException;

class JwtService
{
    public function issue(array $subject, array $claims = []): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => config('jwt.issuer'),
            'aud' => config('jwt.audience'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + (int) config('jwt.ttl_seconds'),
            'sub' => (string) $subject['id'],
            'user' => $subject,
        ], $claims);

        $header = ['typ' => 'JWT', 'alg' => 'RS256'];
        $signingInput = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $privateKey = $this->readKey((string) config('jwt.private_key_path'));

        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign JWT token.');
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

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

        return $payload;
    }

    private function readKey(string $path): string
    {
        $key = @file_get_contents($path);

        if ($key === false) {
            throw new RuntimeException("JWT key is not readable at [{$path}].");
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
