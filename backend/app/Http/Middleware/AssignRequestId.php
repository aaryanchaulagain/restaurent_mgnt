<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-Id', '');
        $id = $this->sanitize($incoming) ?? (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        $request->headers->set('X-Request-Id', $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    private function sanitize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64) {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            return null;
        }

        return $value;
    }
}
