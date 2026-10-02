<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        /*
        |----------------------------------------------------------------------
        | Discogs channels
        |----------------------------------------------------------------------
        |
        | Same stack shape as the section channels below, so Discogs failures
        | also reach laravel.log while the integration narrative stays in its
        | own file. Call sites keep using Log::channel('discogs') rather than
        | the LogsToChannel trait: these logs are emitted from classes that
        | belong to other sections (models, commands, HasUploadedFiles), so the
        | channel follows the subject matter, not the class.
        |
        */

        'discogs' => [
            'driver' => 'stack',
            'channels' => ['discogs_file', 'errors'],
            'ignore_exceptions' => false,
        ],

        'discogs_file' => [
            'driver' => 'single',
            'path' => storage_path('logs/discogs.log'),
            'level' => env('LOG_LEVEL_DISCOGS', 'info'),
            'replace_placeholders' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Section channels
        |----------------------------------------------------------------------
        |
        | Each section of the app logs its own narrative to its own file. The
        | channel is a stack fanning into that file plus the shared 'errors'
        | sink below, so error and above also reach laravel.log while info and
        | warning stay in the section file.
        |
        | Rotation is logrotate's job, not Laravel's, so these use the 'single'
        | driver and carry no 'days' key. Monolog cannot rotate weekly.
        |
        */

        'errors' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            // Literal, deliberately NOT env('LOG_LEVEL'): the floor is
            // structural so no env var can gag every channel at once.
            'level' => 'error',
            'replace_placeholders' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Unrouted
        |----------------------------------------------------------------------
        |
        | Catch-all for anything reaching the default channel: a Log:: facade
        | call that forgot its section channel, plus framework and vendor
        | output. Paired with 'errors' in LOG_STACK so misrouted records stay
        | visible here instead of being silently dropped, while laravel.log
        | stays an error-only feed.
        |
        | Records landing here are a smell: the call site should be using a
        | section channel.
        |
        */

        'unrouted' => [
            'driver' => 'single',
            'path' => storage_path('logs/unrouted.log'),
            'level' => env('LOG_LEVEL_UNROUTED', 'debug'),
            'replace_placeholders' => true,
        ],

        'wholesale_out' => [
            'driver' => 'stack',
            'channels' => ['wholesale_out_file', 'errors'],
            'ignore_exceptions' => false,
        ],

        'wholesale_out_file' => [
            'driver' => 'single',
            'path' => storage_path('logs/wholesale-out.log'),
            'level' => env('LOG_LEVEL_WHOLESALE_OUT', 'info'),
            'replace_placeholders' => true,
        ],

        'wholesale_in' => [
            'driver' => 'stack',
            'channels' => ['wholesale_in_file', 'errors'],
            'ignore_exceptions' => false,
        ],

        'wholesale_in_file' => [
            'driver' => 'single',
            'path' => storage_path('logs/wholesale-in.log'),
            'level' => env('LOG_LEVEL_WHOLESALE_IN', 'info'),
            'replace_placeholders' => true,
        ],

        'users' => [
            'driver' => 'stack',
            'channels' => ['users_file', 'errors'],
            'ignore_exceptions' => false,
        ],

        'users_file' => [
            'driver' => 'single',
            'path' => storage_path('logs/users.log'),
            'level' => env('LOG_LEVEL_USERS', 'info'),
            'replace_placeholders' => true,
        ],

        'stock' => [
            'driver' => 'stack',
            'channels' => ['stock_file', 'errors'],
            'ignore_exceptions' => false,
        ],

        'stock_file' => [
            'driver' => 'single',
            'path' => storage_path('logs/stock.log'),
            'level' => env('LOG_LEVEL_STOCK', 'info'),
            'replace_placeholders' => true,
        ],

        'exports' => [
            'driver' => 'stack',
            'channels' => ['exports_file', 'errors'],
            'ignore_exceptions' => false,
        ],

        'exports_file' => [
            'driver' => 'single',
            'path' => storage_path('logs/exports.log'),
            'level' => env('LOG_LEVEL_EXPORTS', 'info'),
            'replace_placeholders' => true,
        ],

        'imports' => [
            'driver' => 'stack',
            'channels' => ['imports_file', 'errors'],
            'ignore_exceptions' => false,
        ],

        'imports_file' => [
            'driver' => 'single',
            'path' => storage_path('logs/imports.log'),
            'level' => env('LOG_LEVEL_IMPORTS', 'info'),
            'replace_placeholders' => true,
        ],

    ],

];
