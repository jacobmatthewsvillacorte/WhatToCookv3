<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestPerformanceTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local') || ! filter_var(env('PERF_TIMING', false), FILTER_VALIDATE_BOOLEAN)) {
            return $next($request);
        }

        $requestId = (string) str()->uuid();
        $queryCount = 0;
        $queryTimeMs = 0.0;

        DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs): void {
            $queryCount++;
            $queryTimeMs += $query->time;
        });

        $startedAt = hrtime(true);
        $response = null;

        try {
            $response = $next($request);
        } finally {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $responseBytes = $response ? strlen((string) $response->getContent()) : null;

            Log::info('request.performance', [
                'request_id' => $requestId,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'route' => $request->route()?->getName() ?? $request->route()?->uri(),
                'status' => $response?->getStatusCode() ?? 500,
                'duration_ms' => round($durationMs, 2),
                'query_count' => $queryCount,
                'query_time_ms' => round($queryTimeMs, 2),
                'response_bytes' => $responseBytes,
            ]);
        }

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('Server-Timing', sprintf('app;dur=%.2f,sql;dur=%.2f', $durationMs, $queryTimeMs));

        return $response;
    }
}
