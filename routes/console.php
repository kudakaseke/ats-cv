<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('billing:maintain')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('credits:maintain')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('cv:prune-exports')->daily()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->daily()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
