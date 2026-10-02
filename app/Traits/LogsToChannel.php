<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

/**
 * Routes a class's logging to its section channel.
 *
 * Implementers declare their channel once via logChannel(); every call site
 * then uses $this->logInfo(...) and friends rather than naming the channel
 * inline. Section channels fan into their own file plus the shared 'errors'
 * sink, so error and above also reach laravel.log while info and warning stay
 * in the section narrative.
 */
trait LogsToChannel
{
    /**
     * The logging channel this class writes to, as configured in
     * config/logging.php.
     */
    abstract protected function logChannel(): string;

    /**
     * A routine event worth keeping in production: state transitions, stock
     * movements, anything needed to reconstruct what happened after the fact.
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Log::channel($this->logChannel())->info($message, $context);
    }

    /**
     * Step-by-step detail that is only useful while actively debugging.
     * Emitted when the section channel is set to 'debug'.
     */
    protected function logDetail(string $message, array $context = []): void
    {
        Log::channel($this->logChannel())->debug($message, $context);
    }

    /**
     * An expected edge case the code handled. Stays in the section file only.
     */
    protected function logWarning(string $message, array $context = []): void
    {
        Log::channel($this->logChannel())->warning($message, $context);
    }

    /**
     * Something went wrong that a human should look at. Also lands in
     * laravel.log via the shared 'errors' sink.
     *
     * Do not put $e->getTraceAsString() in $context: it added ~22KB per entry
     * of mostly vendor frames. AddRequestContextToLogs already attaches the
     * url, route and method, which is what locating a failure actually needs.
     */
    protected function logError(string $message, array $context = []): void
    {
        Log::channel($this->logChannel())->error($message, $context);
    }
}
