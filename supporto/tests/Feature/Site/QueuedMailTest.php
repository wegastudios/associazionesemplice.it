<?php

namespace Tests\Feature\Site;

use App\Mail\HighPriorityTicket;
use App\Mail\NewTicketForStaff;
use App\Mail\StaffReplied;
use App\Mail\TicketReceived;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionClass;
use Tests\TestCase;

class QueuedMailTest extends TestCase
{
    public function test_queued_support_mails_share_an_hourly_limiter_and_retry_for_a_day(): void
    {
        foreach ([HighPriorityTicket::class, NewTicketForStaff::class, StaffReplied::class, TicketReceived::class] as $class) {
            $mail = (new ReflectionClass($class))->newInstanceWithoutConstructor();

            $middleware = $mail->middleware();
            $this->assertCount(1, $middleware);
            $this->assertInstanceOf(RateLimited::class, $middleware[0]);
            $this->assertSame([60, 300, 900, 3600], $mail->backoff);
            $this->assertTrue($mail->retryUntil() > now()->addHours(23));
        }
    }

    public function test_the_limiter_allows_twenty_mails_an_hour(): void
    {
        config(['support.mail_per_hour' => 20]);
        $limit = RateLimiter::limiter('support-mail')();

        $this->assertSame(20, $limit->maxAttempts);
        $this->assertSame(3600, $limit->decaySeconds);
    }
}
