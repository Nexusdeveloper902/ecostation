<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Photo retention (Task 13): old deposit photos and terminal pending
// captures are purged daily; the deposit, event and points stay.
Schedule::command('photos:purge')->daily();
