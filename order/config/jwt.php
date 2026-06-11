<?php

return [
    'issuer' => env('JWT_ISSUER', 'user-service'),
    'audience' => env('JWT_AUDIENCE', 'order-processing-system'),
    'public_key_path' => env('JWT_PUBLIC_KEY_PATH', storage_path('keys/user_jwt_public.pem')),
];
