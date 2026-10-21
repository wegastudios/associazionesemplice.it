<?php

namespace Tests\Feature\Hub;

use App\Services\Hub\TicketIdGenerator;
use Carbon\CarbonImmutable;
use Tests\HubTestCase;

class TicketIdTest extends HubTestCase
{
    public function test_id_is_seconds_since_27_december_2023_utc(): void
    {
        $gen = app(TicketIdGenerator::class);

        $this->assertSame(86400, $gen->next(CarbonImmutable::parse('2023-12-28T00:00:00Z')));
    }

    public function test_requests_in_the_same_second_get_consecutive_ids(): void
    {
        $gen = app(TicketIdGenerator::class);
        $now = CarbonImmutable::parse('2026-10-17T10:00:00Z');

        $a = $gen->next($now);
        $b = $gen->next($now);
        $c = $gen->next($now);

        $this->assertSame($a + 1, $b);
        $this->assertSame($b + 1, $c);
    }

    public function test_id_never_goes_back_even_if_the_clock_does(): void
    {
        $gen = app(TicketIdGenerator::class);

        $late = $gen->next(CarbonImmutable::parse('2026-10-17T10:00:00Z'));
        $earlier = $gen->next(CarbonImmutable::parse('2026-10-17T09:00:00Z'));

        $this->assertGreaterThan($late, $earlier);
    }

    public function test_id_follows_the_clock_when_it_moves_forward(): void
    {
        $gen = app(TicketIdGenerator::class);

        $a = $gen->next(CarbonImmutable::parse('2026-10-17T10:00:00Z'));
        $b = $gen->next(CarbonImmutable::parse('2026-10-17T10:00:10Z'));

        $this->assertSame($a + 10, $b);
    }

    public function test_epoch_is_configurable(): void
    {
        config(['support.ticket_epoch' => '2026-01-01T00:00:00Z']);

        $this->assertSame(3600, app(TicketIdGenerator::class)->next(CarbonImmutable::parse('2026-01-01T01:00:00Z')));
    }
}
