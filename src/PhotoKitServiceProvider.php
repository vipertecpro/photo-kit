<?php

namespace Vipertecpro\PhotoKit;

use Illuminate\Support\ServiceProvider;
use Vipertecpro\PhotoKit\Commands\CopyAssetsCommand;

class PhotoKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PhotoKit::class, function () {
            return new PhotoKit;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CopyAssetsCommand::class,
            ]);
        }
    }
}
