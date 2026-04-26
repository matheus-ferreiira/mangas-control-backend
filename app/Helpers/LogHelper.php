<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class LogHelper
{
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'token', 'secret', 'authorization'];

    public static function info(string $message, array $context = []): void
    {
        Log::channel('daily')->info($message, self::enrich($context));
    }

    public static function error(string $message, array $context = [], ?\Throwable $exception = null): void
    {
        $ctx = self::enrich($context);

        if ($exception !== null) {
            $ctx['exception'] = [
                'class'   => get_class($exception),
                'message' => $exception->getMessage(),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'trace'   => $exception->getTraceAsString(),
            ];
        }

        Log::stack(['errors', 'daily'])->error($message, $ctx);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (!config('app.debug')) {
            return;
        }

        Log::channel('daily')->debug($message, self::enrich($context));
    }

    public static function warning(string $message, array $context = []): void
    {
        Log::channel('daily')->warning($message, self::enrich($context));
    }

    private static function enrich(array $context): array
    {
        $base = [
            'env'       => config('app.env'),
            'timestamp' => now()->toIso8601String(),
        ];

        if (auth()->hasUser()) {
            $base['user_id'] = auth()->id();
        }

        return array_merge($base, self::sanitize($context));
    }

    private static function sanitize(array $data): array
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '[REDACTED]';
            }
        }

        return $data;
    }
}
