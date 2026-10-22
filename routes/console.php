<?php

use App\Services\DayCloseService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('day-close:run', function () {
    $result = app(DayCloseService::class)->closeToday();
    $date = $result['data']->date;

    if ($result['already_closed']) {
        $this->info('Day already closed for ' . $date);
        return;
    }

    $this->info('Closed day ' . $date);
})->purpose('Close the current Bangladesh day if it is still open');

Schedule::command('day-close:run')
    ->dailyAt('23:50')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping();
