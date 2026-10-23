<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Lock the dashboard down to its own origin: nonce-only scripts, no framing, no sniffing.
     *
     * Styles allow 'unsafe-inline' because Vue style bindings and the UI libraries set style attributes.
     * The CSP is skipped while the Vite dev server is running, since its scripts come from another origin.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::isRunningHot() ? null : Vite::useCspNonce();

        $response = $next($request);

        $response->headers->add(array_filter([
            'Content-Security-Policy' => $nonce === null ? null : implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'unsafe-inline'",
                // News articles show images hosted by their publishers.
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self' https://github.com",
                "frame-ancestors 'none'",
            ]),
            'Strict-Transport-Security' => $request->isSecure() ? 'max-age=31536000; includeSubDomains' : null,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ]));

        return $response;
    }
}
