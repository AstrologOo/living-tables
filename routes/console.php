<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('graph:schedule-sources')->everyMinute();
Schedule::command('graph:recover-stuck-nodes')->everyMinute();
// Expired API tokens are rejected anyway; this only keeps the table small.
Schedule::command('sanctum:prune-expired')->daily();
