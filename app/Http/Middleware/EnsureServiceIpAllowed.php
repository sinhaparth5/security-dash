<?php

namespace App\Http\Middleware;

use App\Models\Service;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnsureServiceIpAllowed
{
    /**
     * Reject a service's token when it is used from outside the service's allowlist.
     * An empty allowlist means any address, so existing services keep working.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $service = $request->user();

        if ($service instanceof Service && $service->allowed_ips && ! IpUtils::checkIp((string) $request->ip(), $service->allowed_ips)) {
            abort(403, 'This token is not allowed from '.$request->ip().'.');
        }

        return $next($request);
    }
}
