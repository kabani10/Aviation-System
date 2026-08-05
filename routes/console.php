<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// See app/Domain/FlightRequests/Actions/SendFlightRequestDigests.php.
// Morning-of delivery so operators see it at the start of the workday,
// not scattered through the night.
Schedule::command('app:send-flight-request-digests')->dailyAt('07:00');
