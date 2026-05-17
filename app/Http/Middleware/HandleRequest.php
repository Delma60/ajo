<?php

namespace App\Http\Middleware;

use App\Http\Resources\UserResource;
use App\Models\General;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HandleRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // return $next($request);
        $this->transformRequest($request);

        /** @var Response $response */
        $response = $next($request);

        // 🔹 Transform the response before sending to browser
        return $this->transformResponse($request, $response);
    }

    protected function transformRequest(Request $request): void
    {
        // Example: add a global request attribute
        $request->attributes->set('request_id', uniqid('req_', true));
    }

    protected function transformResponse(Request $request, Response $response): Response
{
    if ($this->isJsonResponse($response)) {
        $data = json_decode($response->getContent(), true) ?? [];

        $payload = [
            'success' => $response->isSuccessful(),
            'meta'    => $this->share($request),
        ];

        // If validation or error response, keep errors + message
        if (isset($data['errors']) || $response->getStatusCode() >= 400) {
            $payload['message'] = $data['message'] ?? $response->statusText ?? 'Error';
            $payload['errors']  = $data['errors'] ?? null;
            $payload['data']    = $data['data'] ?? null;
        } else {
            // Normal success response
            $payload['data'] = $data['data'] ?? $data;
        }

        $response->setContent(json_encode($payload));
    }

    return $response;
}

protected function isJsonResponse(Response $response): bool
{
    $contentType = $response->headers->get('Content-Type', '');
    return str_starts_with($contentType, 'application/json');
}


    protected function share(Request $request): array
    {
        return [
            'timestamp' => now()->toDateTimeString(),
            'path' => $request->path(),
            "app" =>[], // General::find(1),
            "url" => url("/"),
            "auth" => [
                'user' => Auth::user(),
            ],
            'request_id' => $request->attributes->get('request_id'),
        ];
    }

}
