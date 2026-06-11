<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Support\RedisStreamPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function __construct(private readonly RedisStreamPublisher $events) {}

    public function show(Request $request, int $orderId): JsonResponse
    {
        $report = Report::query()
            ->where('order_id', $orderId)
            ->where('user_id', (int) data_get($request->attributes->get('jwt_payload'), 'user.id'))
            ->latest()
            ->firstOrFail();

        return response()->json(['report' => $report]);
    }

    public function download(Request $request, int $orderId)
    {
        $report = Report::query()
            ->where('order_id', $orderId)
            ->where('user_id', (int) data_get($request->attributes->get('jwt_payload'), 'user.id'))
            ->latest()
            ->firstOrFail();

        return Storage::disk('local')->download($report->file_path, basename($report->file_path), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            'type' => ['required', 'string', 'in:receipt,cancellation'],
            'status' => ['required', 'string'],
            'total_amount' => ['required', 'numeric'],
            'currency' => ['required', 'string', 'size:3'],
            'items' => ['required', 'array'],
        ]);

        $report = Report::query()->where([
            'order_id' => $data['order_id'],
            'type' => $data['type'],
        ])->first();

        if (! $report) {
            $path = 'reports/order-'.$data['order_id'].'-'.$data['type'].'.pdf';
            Storage::disk('local')->put($path, $this->renderPseudoPdf($data));

            $report = Report::query()->create([
                'order_id' => $data['order_id'],
                'user_id' => $data['user_id'],
                'type' => $data['type'],
                'status' => 'generated',
                'file_path' => $path,
                'payload' => $data,
            ]);

            $this->events->publish('report.generated', [
                'report_id' => $report->id,
                'order_id' => $report->order_id,
                'user_id' => $report->user_id,
                'type' => $report->type,
            ]);
        }

        return response()->json(['report' => $report]);
    }

    private function renderPseudoPdf(array $data): string
    {
        $lines = [
            'Online Shop Report',
            'Type: '.$data['type'],
            'Order ID: '.$data['order_id'],
            'User ID: '.$data['user_id'],
            'Status: '.$data['status'],
            'Date: '.now()->toIso8601String(),
            '',
            'Items:',
        ];

        foreach ($data['items'] as $item) {
            $lines[] = sprintf(
                '- %s x%s @ %s = %s %s',
                $item['name'] ?? ('Product #'.$item['product_id']),
                $item['quantity'],
                $item['unit_price'],
                $item['total_price'],
                $data['currency'],
            );
        }

        $lines[] = '';
        $lines[] = 'Total: '.$data['total_amount'].' '.$data['currency'];

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
