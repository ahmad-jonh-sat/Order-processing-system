<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

class RedisStreamPublisher
{
    public function publish(string $stream, array $payload): void
    {
        Redis::connection()->command('xadd', [
            $stream,
            '*',
            'event',
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
