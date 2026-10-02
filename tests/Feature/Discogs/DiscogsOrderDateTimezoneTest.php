<?php

namespace Tests\Feature\Discogs;

use App\Models\Sale;
use App\Services\External\DiscogsClient;
use App\Services\External\DiscogsOrderProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Discogs order `created` timestamp arrives in Discogs' own offset (US Pacific,
 * e.g. -07:00). The sale `date` must be resolved to the shop timezone (Europe/Rome)
 * so it matches what the seller sees on Discogs. Without conversion, orders placed in
 * the early Italian morning were recorded one calendar day early.
 *
 * These tests feed crafted order payloads straight into the processor, so they need no
 * Discogs account or listings. Items are left empty: the Sale row (and its date) is
 * created up-front, before any item processing, so an empty items array isolates the
 * date logic cleanly.
 */
class DiscogsOrderDateTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function processOrder(string $orderId, string $created): Sale
    {
        // Items are empty, so the DiscogsClient is never touched; a stub is enough.
        $processor = new DiscogsOrderProcessor($this->createStub(DiscogsClient::class));

        $processor->processOrder([
            'id' => $orderId,
            'buyer' => ['id' => 1, 'username' => 'tester'],
            'created' => $created,
            'items' => [],
        ]);

        return Sale::where('discogs_order_id', $orderId)->firstOrFail();
    }

    public function test_early_morning_rome_order_keeps_its_rome_calendar_day(): void
    {
        // 23:23 on Jul 19 US Pacific == 08:23 on Jul 20 in Rome.
        // Before the fix this was recorded as 2026-07-19.
        $sale = $this->processOrder('test-69324', '2026-07-19T23:23:31-07:00');

        $this->assertEquals('2026-07-20', $sale->date->toDateString());
    }

    public function test_afternoon_rome_order_is_unaffected(): void
    {
        // 06:41 on Jul 20 US Pacific == 15:41 on Jul 20 in Rome: same day either way.
        $sale = $this->processOrder('test-69325', '2026-07-20T06:41:02-07:00');

        $this->assertEquals('2026-07-20', $sale->date->toDateString());
    }

    public function test_order_just_after_rome_midnight_rolls_to_the_new_day(): void
    {
        // 15:30 on Jul 19 US Pacific == 00:30 on Jul 20 in Rome. The Pacific date (Jul 19)
        // is a full day behind the Rome date, which is what the shop and Discogs display.
        $sale = $this->processOrder('test-midnight', '2026-07-19T15:30:00-07:00');

        $this->assertEquals('2026-07-20', $sale->date->toDateString());
    }
}
