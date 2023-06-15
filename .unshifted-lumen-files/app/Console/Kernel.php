<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        'App\Console\Commands\ProcessImages',
        'App\Console\Commands\RegenerateProofs',
        'App\Console\Commands\ProcessErrors',
        'App\Console\Commands\CheckShow',
        'App\Console\Commands\ReUpload',
    ];

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        //
    }
}
