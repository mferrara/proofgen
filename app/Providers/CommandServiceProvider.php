<?php namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Console\Commands\ProcessImages;

class CommandServiceProvider extends ServiceProvider
{

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('command.proofgen.process', function()
        {
            return new ProcessImages;
        });

        $this->commands(
            'command.proofgen.process'
        );
    }
}