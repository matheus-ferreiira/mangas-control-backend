<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestLoggerMiddleware
{
    private const SENSITIVE_FIELDS = ['password', 'password_confirmation', 'token', 'secret'];

    private const SKIP_PATHS = ['api/logs*', 'api/docs*', 'api/db-test'];

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        if (config('logging.log_requests', false) && !$this->shouldSkip($request)) {
            $this->logRequest($request, $response, microtime(true) - $start);
        }

        return $response;
    }

    private function logRequest(Request $request, Response $response, float $elapsed): void
    {
        Log::channel('access')->info('HTTP '.$request->method(), [
            'method'      => $request->method(),
            'path'        => $request->path(),
            'status'      => $response->getStatusCode(),
            'duration_ms' => round($elapsed * 1000, 2),
            'ip'          => $request->ip(),
            'user_id'     => optional(auth()->user())->id,
            'user_agent'  => $request->userAgent(),
            'input'       => collect($request->all())->except(self::SENSITIVE_FIELDS)->toArray(),
        ]);
    }

    private function shouldSkip(Request $request): bool
    {
        foreach (self::SKIP_PATHS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
