<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies TRUSTED_PROXIES from config at request time (safe with config:cache).
 */
class TrustProxiesFromConfig extends Middleware
{
    protected $proxies;

    /**
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('app.trusted_proxies');
        if (filled($configured)) {
            $this->proxies = $configured === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', (string) $configured))));
        }

        return parent::handle($request, $next);
    }
}
