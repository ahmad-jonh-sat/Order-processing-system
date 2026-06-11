<?php

return [
    'issuer' => env('JWT_ISSUER', 'user-service'),
    'audience' => env('JWT_AUDIENCE', 'order-processing-system'),
    'ttl_seconds' => (int) env('JWT_TTL_SECONDS', 3600),
    'private_key_path' => env('JWT_PRIVATE_KEY_PATH', storage_path('keys/jwt_private.pem')),
    'public_key_path' => env('JWT_PUBLIC_KEY_PATH', storage_path('keys/jwt_public.pem')),
];
