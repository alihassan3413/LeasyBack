<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * B2B offer approval reminders (b2b.txt §18). Runs daily; the 24 h spacing and
 * every stop condition are enforced inside the command, so an extra run can
 * never produce a duplicate reminder. `withoutOverlapping` guards against two
 * workers racing the same due set.
 */
Schedule::command('b2b:send-offer-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Partner webhook outbox sweeper (§12.16). Re-queues events that committed but
 * whose dispatch was lost, and retries whose delayed job never survived. Every
 * action it takes is idempotent — fan-out is unique per (event, subscription)
 * and a succeeded delivery is never re-sent — so a duplicate run cannot produce
 * a duplicate webhook.
 */
Schedule::command('partner:webhooks:dispatch-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * offer.expired has no button behind it — an offer expires because a date
 * passed. This is its writer. `expired_notified_at` makes it exactly-once, so
 * an extra run cannot produce a second event.
 */
Schedule::command('partner:webhooks:emit-expired-offers')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->onOneServer();
