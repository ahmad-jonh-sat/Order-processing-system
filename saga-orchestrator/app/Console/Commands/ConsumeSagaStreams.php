<?php

namespace App\Console\Commands;

use App\Support\OrderSagaManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ConsumeSagaStreams extends Command
{
    protected $signature = 'saga:consume-streams';

    protected $description = 'Consume Redis Streams and orchestrate order sagas.';

    public function handle(OrderSagaManager $manager): int
    {
        $stream = env('REDIS_STREAM_ORDER_CREATED', 'order.created');
        $group = env('SAGA_CONSUMER_GROUP', 'saga-orchestrator');
        $consumer = env('SAGA_CONSUMER_NAME', gethostname() ?: 'saga-orchestrator-1');

        $this->ensureGroup($stream, $group);

        while (true) {
            $messages = Redis::connection()->command('xreadgroup', [
                'group',
                $group,
                $consumer,
                'count',
                10,
                'block',
                5000,
                'streams',
                $stream,
                '>',
            ]);

            foreach (($messages[$stream] ?? []) as $messageId => $fields) {
                try {
                    $payload = json_decode($fields['event'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                    $manager->start($payload);
                    Redis::connection()->command('xack', [$stream, $group, $messageId]);
                } catch (\Throwable $exception) {
                    $this->error("Failed {$messageId}: {$exception->getMessage()}");
                }
            }
        }
    }

    private function ensureGroup(string $stream, string $group): void
    {
        try {
            Redis::connection()->command('xgroup', ['create', $stream, $group, '0', 'mkstream']);
        } catch (\Throwable) {
            // BUSYGROUP means the consumer group already exists.
        }
    }
}
