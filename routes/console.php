<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('payments:expire-pending')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('reports:snapshot-daily')
    ->dailyAt('00:15')
    ->timezone((string) config('app.display_timezone', 'Asia/Jakarta'))
    ->withoutOverlapping();
