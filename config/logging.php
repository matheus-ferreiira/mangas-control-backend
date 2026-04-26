<?php

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace'   => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    // Whether to log all HTTP requests (set LOG_REQUESTS=true in .env to enable)
    'log_requests' => env('LOG_REQUESTS', false),

    'channels' => [

        // Default stack: daily file + stderr (captured by Railway/cloud platforms)
        'stack' => [
            'driver'            => 'stack',
            'channels'          => explode(',', (string) env('LOG_STACK', 'daily,stderr')),
            'ignore_exceptions' => false,
        ],

        // General rotating log — all levels, 14-day retention
        'daily' => [
            'driver'               => 'daily',
            'path'                 => storage_path('logs/laravel.log'),
            'level'                => env('LOG_LEVEL', 'debug'),
            'days'                 => env('LOG_DAILY_DAYS', 14),
            'formatter'            => JsonFormatter::class,
            'replace_placeholders' => true,
        ],

        // Errors-only log — easier to scan in production
        'errors' => [
            'driver'               => 'daily',
            'path'                 => storage_path('logs/errors.log'),
            'level'                => 'error',
            'days'                 => env('LOG_DAILY_DAYS', 14),
            'formatter'            => JsonFormatter::class,
            'replace_placeholders' => true,
        ],

        // HTTP access log — written by RequestLoggerMiddleware
        'access' => [
            'driver'               => 'daily',
            'path'                 => storage_path('logs/access.log'),
            'level'                => 'info',
            'days'                 => env('LOG_DAILY_DAYS', 14),
            'formatter'            => JsonFormatter::class,
            'replace_placeholders' => true,
        ],

        'single' => [
            'driver'               => 'single',
            'path'                 => storage_path('logs/laravel.log'),
            'level'                => env('LOG_LEVEL', 'debug'),
            'formatter'            => JsonFormatter::class,
            'replace_placeholders' => true,
        ],

        // Stderr — Railway and other cloud platforms capture this stream
        'stderr' => [
            'driver'     => 'monolog',
            'level'      => env('LOG_LEVEL', 'debug'),
            'handler'    => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter'  => JsonFormatter::class,
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'slack' => [
            'driver'               => 'slack',
            'url'                  => env('LOG_SLACK_WEBHOOK_URL'),
            'username'             => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji'                => env('LOG_SLACK_EMOJI', ':boom:'),
            'level'                => env('LOG_SLACK_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver'    => 'monolog',
            'level'     => env('LOG_LEVEL', 'debug'),
            'handler'   => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host'             => env('PAPERTRAIL_URL'),
                'port'             => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver'               => 'syslog',
            'level'                => env('LOG_LEVEL', 'debug'),
            'facility'             => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver'               => 'errorlog',
            'level'                => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver'  => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
