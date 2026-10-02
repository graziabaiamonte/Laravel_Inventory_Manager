<?php

namespace Tests\Feature\Logging;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SectionChannelsTest extends TestCase
{
    private function read(string $file): string
    {
        return @file_get_contents(storage_path("logs/$file.log")) ?: '';
    }

    private function clear(string ...$files): void
    {
        foreach ($files as $f) {
            @unlink(storage_path("logs/$f.log"));
        }
    }

    public function test_section_info_and_warnings_stay_out_of_laravel_log(): void
    {
        $this->clear('wholesale-out', 'laravel');

        Log::channel('wholesale_out')->info('SECTION_INFO');
        Log::channel('wholesale_out')->warning('SECTION_WARNING');
        Log::channel('wholesale_out')->error('SECTION_ERROR');

        $section = $this->read('wholesale-out');
        $this->assertStringContainsString('SECTION_INFO', $section);
        $this->assertStringContainsString('SECTION_WARNING', $section);
        $this->assertStringContainsString('SECTION_ERROR', $section);

        // laravel.log is an error feed: the narrative must not leak into it.
        $errors = $this->read('laravel');
        $this->assertStringNotContainsString('SECTION_INFO', $errors);
        $this->assertStringNotContainsString('SECTION_WARNING', $errors);
        $this->assertStringContainsString('SECTION_ERROR', $errors);
    }

    public function test_global_log_level_cannot_silence_a_section_channel(): void
    {
        // The regression this whole refactor exists to prevent: every channel
        // used to read env('LOG_LEVEL'), so LOG_LEVEL=warning gagged them all.
        config(['logging.channels.wholesale_out_file.level' => 'info']);
        config(['logging.channels.single.level' => 'warning']);

        $this->clear('wholesale-out');
        Log::channel('wholesale_out')->info('SURVIVES_GLOBAL_LEVEL');

        $this->assertStringContainsString('SURVIVES_GLOBAL_LEVEL', $this->read('wholesale-out'));
    }

    public function test_discogs_narrative_stays_in_its_own_file_but_errors_reach_laravel_log(): void
    {
        // The Discogs channels were retrofitted from 'daily' onto the same
        // stack shape. Call sites still say Log::channel('discogs'), so the
        // channel name must keep working unchanged.
        $this->clear('discogs', 'laravel');

        Log::channel('discogs')->info('DISCOGS_INFO');
        Log::channel('discogs')->error('DISCOGS_ERROR');

        $discogs = $this->read('discogs');
        $this->assertStringContainsString('DISCOGS_INFO', $discogs);
        $this->assertStringContainsString('DISCOGS_ERROR', $discogs);

        $errors = $this->read('laravel');
        $this->assertStringNotContainsString('DISCOGS_INFO', $errors);
        $this->assertStringContainsString('DISCOGS_ERROR', $errors);
    }

    public function test_a_stray_facade_call_is_visible_rather_than_dropped(): void
    {
        // The default stack is 'unrouted,errors': a Log:: call that forgot its
        // section channel must not vanish, but must not pollute laravel.log.
        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['unrouted', 'errors'],
        ]);
        $this->app->forgetInstance('log');
        Log::clearResolvedInstances();
        $this->clear('unrouted', 'laravel');

        Log::info('STRAY_FACADE_INFO');
        Log::error('STRAY_FACADE_ERROR');

        $unrouted = $this->read('unrouted');
        $this->assertStringContainsString('STRAY_FACADE_INFO', $unrouted);
        $this->assertStringContainsString('STRAY_FACADE_ERROR', $unrouted);

        $errors = $this->read('laravel');
        $this->assertStringNotContainsString('STRAY_FACADE_INFO', $errors);
        $this->assertStringContainsString('STRAY_FACADE_ERROR', $errors);
    }

    public function test_request_context_reaches_a_named_section_channel(): void
    {
        // Log::withContext() would NOT satisfy this: it only reaches the
        // default channel. AddRequestContextToLogs uses the Context facade,
        // which Laravel injects into every named channel.
        $this->clear('wholesale-out');

        $this->app['router']->get('/section-channel-probe', function () {
            Log::channel('wholesale_out')->error('CONTEXT_PROBE');

            return 'ok';
        })->middleware('web')->name('probe.section.channel');

        $this->get('/section-channel-probe')->assertOk();

        $log = $this->read('wholesale-out');
        $this->assertStringContainsString('"route":"probe.section.channel"', $log);
        $this->assertStringContainsString('/section-channel-probe', $log);
        $this->assertStringContainsString('"method":"GET"', $log);
    }
}
