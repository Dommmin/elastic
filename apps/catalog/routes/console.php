<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bez tego wpisu (i procesu `schedule:work`, kontener catalog-scheduler)
// zamówienia nie wygasają, a towar zostaje zarezerwowany.
Schedule::command('orders:expire-overdue')->everyMinute()->withoutOverlapping();
