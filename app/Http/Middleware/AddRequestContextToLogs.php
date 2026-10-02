<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches the current request to every log record for this request.
 *
 * Knowing the route and URL is usually enough to locate a failure, which is
 * why call sites do not dump stack traces into the log context: the trace was
 * mostly vendor frames behind a deploy-specific release hash, while this gives
 * the same orientation on every line rather than only on errors.
 *
 * Uses the Context facade rather than Log::withContext(). Log::withContext()
 * only reaches the default channel, so it would silently miss every
 * Log::channel(...) call, which is all of the section channels.
 */
class AddRequestContextToLogs
{
    public function handle(Request $request, Closure $next): Response
    {
        Context::add([
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'route' => $request->route()?->getName(),
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }
}
