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
