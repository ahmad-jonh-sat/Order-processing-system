<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class ProxyController extends Controller
{
    public function userAuth(Request $request, ?string $path = null): Response
    {
        return $this->proxy($request, config('services.user.url'), 'api/auth', $path);
    }

    public function users(Request $request, ?string $path = null): Response
    {
        return $this->proxy($request, config('services.user.url'), 'api/users', $path);
    }

    public function orders(Request $request, ?string $path = null): Response
    {
        return $this->proxy($request, config('services.order.url'), 'api', $path);
    }

    public function reports(Request $request, ?string $path = null): Response
    {
        return $this->proxy($request, config('services.report.url'), 'api', $path);
    }

    public function shopCreateOrder(Request $request): Response
    {
        return $this->proxy($request, config('services.order.url'), 'api/shop/orders');
    }

    public function shopGetOrder(Request $request, int $id): Response
    {
        return $this->proxy($request, config('services.order.url'), 'api/shop/orders', (string) $id);
    }

    public function shopCancelOrder(Request $request, int $id): Response
    {
        return $this->proxy($request, config('services.saga.url'), 'api/internal/sagas/orders', "{$id}/cancel");
    }

    public function shopGetReport(Request $request, int $orderId): Response
    {
        return $this->proxy($request, config('services.report.url'), 'api/shop/reports', (string) $orderId);
    }

    public function shopDownloadReport(Request $request, int $orderId): Response
    {
        return $this->proxy($request, config('services.report.url'), 'api/shop/reports', "{$orderId}/download");
    }

    public function shopTopUpWallet(Request $request): Response
    {
        return $this->proxy($request, config('services.user.url'), 'api/shop/wallet/top-up');
    }

    public function shopWallet(Request $request): Response
    {
        return $this->proxy($request, config('services.user.url'), 'api/shop/wallet');
    }

    public function shopProducts(Request $request): Response
    {
        return $this->proxy($request, config('services.order.url'), 'api/products');
    }

    private function proxy(Request $request, string $baseUrl, string $prefix, ?string $path = null): Response
    {
        $targetUrl = $this->targetUrl($baseUrl, $prefix, $path, $request->getQueryString());
        $response = Http::withHeaders($this->forwardHeaders($request))
            ->send($request->method(), $targetUrl, $this->requestOptions($request));

        return response($response->body(), $response->status())
            ->withHeaders($this->responseHeaders($response));
    }

    private function targetUrl(string $baseUrl, string $prefix, ?string $path, ?string $query): string
    {
        $url = rtrim($baseUrl, '/') . '/' . trim($prefix, '/');

        if ($path !== null && $path !== '') {
            $url .= '/' . ltrim($path, '/');
        }

        return $query ? $url . '?' . $query : $url;
    }

    private function forwardHeaders(Request $request): array
    {
        $headers = [];

        foreach (['Authorization', 'Accept', 'Content-Type', 'X-Request-Id'] as $header) {
            if ($request->headers->has($header)) {
                $headers[$header] = $request->headers->get($header);
            }
        }

        return $headers;
    }

    private function requestOptions(Request $request): array
    {
        if ($request->isJson()) {
            return ['json' => $request->json()->all()];
        }

        if ($request->request->count() > 0) {
            return ['form_params' => $request->request->all()];
        }

        $content = $request->getContent();

        return $content !== '' ? ['body' => $content] : [];
    }

    private function responseHeaders(ClientResponse $response): array
    {
        $headers = [];
        $contentType = $response->header('Content-Type');

        if ($contentType) {
            $headers['Content-Type'] = $contentType;
        }

        return $headers;
    }
}
