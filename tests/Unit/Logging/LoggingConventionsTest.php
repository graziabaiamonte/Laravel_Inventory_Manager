<?php

namespace Tests\Unit\Logging;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards the section channel logging conventions built around LogsToChannel.
 *
 * These are static checks over app/ rather than behavioural tests: they catch
 * the failure modes that are silent at runtime, which no feature test would
 * surface. Fast by design - no database, no HTTP.
 */
class LoggingConventionsTest extends TestCase
{
    /** The one call site that legitimately names its channel directly. */
    private const FACADE_EXCEPTION = 'app/Traits/Controllers/HasUploadedFiles.php';

    /** @return array<string,string> relative path => source */
    private function appFiles(): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = 'app'.substr($file->getPathname(), strlen(app_path()));
            $out[str_replace(DIRECTORY_SEPARATOR, '/', $rel)] = file_get_contents($file->getPathname());
        }

        return $out;
    }

    /**
     * A typo in logChannel() does not throw - Laravel degrades to the emergency
     * logger and the section's output silently disappears. Nothing else catches this.
     */
    public function test_every_channel_declared_by_the_trait_exists_in_config(): void
    {
        $configured = array_keys(config('logging.channels'));
        $checked = 0;

        foreach ($this->appFiles() as $path => $src) {
            if (! str_contains($src, 'use LogsToChannel;')) {
                continue;
            }

            preg_match('/^namespace\s+([^;]+);/m', $src, $ns);
            preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cls);
            $this->assertNotEmpty($ns, "Could not resolve namespace in {$path}");
            $this->assertNotEmpty($cls, "Could not resolve class name in {$path}");

            $fqcn = trim($ns[1]).'\\'.$cls[1];
            $this->assertTrue(class_exists($fqcn), "Class {$fqcn} not autoloadable from {$path}");

            $ref = new ReflectionClass($fqcn);
            $this->assertTrue(
                $ref->hasMethod('logChannel'),
                "{$path} uses LogsToChannel but does not implement logChannel()"
            );

            // PHP 8.1+ allows invoking non-public methods without setAccessible().
            $channel = $ref->getMethod('logChannel')->invoke($ref->newInstanceWithoutConstructor());

            $this->assertContains(
                $channel,
                $configured,
                "{$path} logs to channel '{$channel}', which is not defined in config/logging.php. ".
                'Laravel would fall back to the emergency logger and this section would log nothing.'
            );
            $checked++;
        }

        $this->assertGreaterThan(10, $checked, 'Expected the trait to be in wide use; found only '.$checked);
    }

    /**
     * $this is unbound in a static method, and in a closure declared inside one.
     * A logInfo() call there is a fatal error the moment that path executes.
     */
    public function test_no_log_call_sits_in_a_static_context(): void
    {
        $violations = [];

        foreach ($this->appFiles() as $path => $src) {
            foreach ($this->staticContextLogCalls($src) as $line => $call) {
                $violations[] = "{$path}:{$line} calls \$this->{$call}() where \$this is unbound";
            }
        }

        $this->assertSame([], $violations, "Log calls in a static context:\n".implode("\n", $violations));
    }

    public function test_no_plain_log_facade_calls_outside_the_documented_exception(): void
    {
        $violations = [];

        foreach ($this->appFiles() as $path => $src) {
            if ($path === self::FACADE_EXCEPTION || str_ends_with($path, 'Traits/LogsToChannel.php')) {
                continue;
            }
            foreach (explode("\n", $src) as $i => $line) {
                $trimmed = trim($line);
                // Skip line comments and docblock/block-comment bodies.
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (preg_match('/(?<![\w>])Log::(info|warning|error|critical|debug|channel)\(/', $line)) {
                    $violations[] = "{$path}:".($i + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Use the LogsToChannel trait instead of the Log facade:\n".implode("\n", $violations)
        );
    }

    /**
     * Returns [line => methodName] for $this->log*() calls whose enclosing scope
     * has no $this. Uses token_get_all rather than a parser library so the guard
     * does not depend on a transitive package.
     *
     * @return array<int,string>
     */
    private function staticContextLogCalls(string $src): array
    {
        $tokens = token_get_all($src);
        $scopes = [];        // stack of bool: true when $this is unbound
        $pending = null;     // staticness awaiting this function's opening brace
        $depth = 0;
        $sawStatic = false;
        $hits = [];

        foreach ($tokens as $i => $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;

                if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }

                if ($id === T_FUNCTION) {
                    // A named method is static only if declared so; a closure
                    // inherits from the scope it is created in.
                    $isClosure = $this->nextMeaningful($tokens, $i) === '(';
                    $inherited = $scopes !== [] ? end($scopes) : false;
                    $pending = $sawStatic || ($isClosure && $inherited);
                }

                if ($id === T_VARIABLE && $text === '$this'
                    && $this->nextMeaningful($tokens, $i) === '->'
                    && $scopes !== [] && end($scopes) === true) {
                    $name = $this->methodNameAfterArrow($tokens, $i);
                    if ($name !== null && str_starts_with($name, 'log')) {
                        $hits[$line] = $name;
                    }
                }

                $sawStatic = ($id === T_STATIC);

                continue;
            }

            if ($token === '{') {
                $depth++;
                $scopes[] = $pending ?? ($scopes !== [] ? end($scopes) : false);
                $pending = null;
            } elseif ($token === '}') {
                $depth--;
                array_pop($scopes);
            }

            if ($token !== '{' && $token !== '}') {
                $sawStatic = false;
            }
        }

        return $hits;
    }

    /** @param array<int,mixed> $tokens */
    private function nextMeaningful(array $tokens, int $from): ?string
    {
        for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return $t[1] === '->' ? '->' : $t[1];
            }

            return $t;
        }

        return null;
    }

    /** @param array<int,mixed> $tokens */
    private function methodNameAfterArrow(array $tokens, int $from): ?string
    {
        $seenArrow = false;
        for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($t) && $t[0] === T_OBJECT_OPERATOR) {
                $seenArrow = true;

                continue;
            }
            if ($seenArrow && is_array($t) && $t[0] === T_STRING) {
                return $t[1];
            }

            return null;
        }

        return null;
    }
}
